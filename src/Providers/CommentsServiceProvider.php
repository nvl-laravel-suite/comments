<?php

declare(strict_types=1);

namespace Nvl\Comments\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Comments\Console\Commands\CommentsDoctorCommand;
use Nvl\Comments\Console\Commands\CommentsReconcileCommand;
use Nvl\Comments\Contracts\CommentAttachmentLifecycle;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentBatchQueryScope;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Contracts\CommentTargetResolver;
use Nvl\Comments\Integrations\MediaCommentAttachmentLifecycle;
use Nvl\Comments\Integrations\MediaCommentRelations;
use Nvl\Comments\Integrations\UnavailableCommentAttachments;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Ownership\CommentsAdoptionAdapter as OwnershipCommentsAdoptionAdapter;
use Nvl\Comments\Ownership\CommentsResourceRegistrar as OwnershipCommentsResourceRegistrar;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentMutationLockStore;
use Nvl\Comments\Services\CommentsDoctor;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;
use Nvl\Comments\Services\UnsupportedCommentBatchQueryScope;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Integrations\OptionalIntegration;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;

/**
 * Registers the standalone, headless Comments package.
 */
final class CommentsServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    public function register(): void
    {
        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/comments', fn (): array => PackageDoctorContributor::reportChecks($this->app->make(CommentsDoctor::class)->inspect(), 'nvl:comments:doctor'));

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-comments.php', 'comments');
        $this->app->singleton(CommentModelRegistry::class);
        $this->app->booting(fn () => $this->app->make(CommentModelRegistry::class)->prepare());
        $this->registerAttachmentAdapters();

        $authorization = config(
            'nvl-comments.authorization.class',
            ConfiguredCommentAuthorization::class,
        );

        if (! is_string($authorization)
            || ! is_a($authorization, CommentAuthorization::class, true)) {
            throw new InvalidArgumentException(
                'nvl-comments.authorization.class must implement CommentAuthorization.',
            );
        }

        $this->app->bindIf(CommentAuthorization::class, $authorization);
        $this->bindConfiguredContract(
            CommentQueryScope::class,
            'nvl-comments.query_scope.class',
            $authorization,
        );
        $this->app->bindIf(CommentBatchQueryScope::class, fn (Application $app): CommentBatchQueryScope => $this->batchScope(
            $app->make(CommentAuthorization::class),
            $app->make(CommentQueryScope::class),
        ));
        $this->app->scoped(CommentMutationLock::class, static fn (Application $app): CommentMutationLock => new CommentMutationLock(
            transactions: static fn (): DatabaseTransactionsManager => $app->make('db.transactions'),
            store: $app->make(CommentMutationLockStore::class),
            boundary: $app->make(OwnershipBoundary::class),
        ));
        $this->app->singleton(CommentMetadataRegistry::class);
        $this->app->singleton(
            CommentMentionResourceRegistry::class,
            static function (Application $app): CommentMentionResourceRegistry {
                $registry = new CommentMentionResourceRegistry($app);
                $registry->registerConfigured();

                return $registry;
            },
        );
        $this->app->singleton(CommentTargetRegistry::class);
    }

    public function boot(
        CommentTargetRegistry $targets,
        CommentMetadataRegistry $metadata,
        Dispatcher $events,
    ): void {
        $this->app->make(OwnershipCommentsResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class));
        if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
            $this->app->make(OwnershipAdoptionRegistry::class)->register('comments', OwnershipCommentsAdoptionAdapter::class);
        }
        $this->app->make(GlobalNames::class)->translations('comments', __DIR__.'/../../lang', $this->app->make('translation.loader'));
        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/nvl-comments'),
        ], 'nvl-comments-translations');
        $this->registerTargets($targets);
        CommentModelRegistry::modelClass()::resolveRelationUsing('mediaAssociations', function (Comment $comment) {
            if (($this->app->getLoadedProviders()[MediaServiceProvider::class] ?? false) !== true) {
                throw new InvalidArgumentException('Comment attachment relationships require the loaded Media provider.');
            }

            return MediaCommentRelations::associations($comment);
        });
        CommentModelRegistry::modelClass()::resolveRelationUsing('media', function (Comment $comment) {
            if (($this->app->getLoadedProviders()[MediaServiceProvider::class] ?? false) !== true) {
                throw new InvalidArgumentException('Comment attachment relationships require the loaded Media provider.');
            }

            return MediaCommentRelations::media($comment);
        });
        CommentModelRegistry::modelClass()::resolveRelationUsing('attachmentAssociations', function (Comment $comment) {
            if (($this->app->getLoadedProviders()[MediaServiceProvider::class] ?? false) !== true) {
                throw new InvalidArgumentException('Comment attachment relationships require the loaded Media provider.');
            }

            return MediaCommentRelations::associations($comment)->where('collection', 'attachments')->where('is_active', true);
        });
        CommentModelRegistry::modelClass()::deleting(function (Comment $comment): void {
            if ($comment->isForceDeleting() && ($this->app->getLoadedProviders()[MediaServiceProvider::class] ?? false) === true) {
                $this->app->make(MediaCommentAttachmentLifecycle::class)->deleteOwnerMedia($comment);
            }
        });
        $this->registerTransactionListeners($events);

        if (config('nvl-comments.migrations.enabled', true) === true) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                CommentsDoctorCommand::class,
                CommentsReconcileCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../../config/nvl-comments.php' => config_path('nvl-comments.php'),
        ], 'nvl-comments-config');
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'nvl-comments-migrations');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'nvl-comments-skills');
    }

    private function registerTransactionListeners(Dispatcher $events): void
    {
        $events->listen(
            TransactionRolledBack::class,
            function (TransactionRolledBack $event): void {
                if (! $this->app->resolved(CommentMutationLock::class)) {
                    return;
                }

                $this->app
                    ->make(CommentMutationLock::class)
                    ->releaseAfterRollback($event);
            },
        );
    }

    /** Derive SQL authorization only from compatible currently active bindings. */
    private function batchScope(CommentAuthorization $authorization, CommentQueryScope $scope): CommentBatchQueryScope
    {
        if ($authorization instanceof ConfiguredCommentAuthorization && $scope instanceof ConfiguredCommentAuthorization) {
            return $authorization;
        }
        if ($authorization === $scope && $authorization instanceof CommentBatchQueryScope) {
            return $authorization;
        }

        return new UnsupportedCommentBatchQueryScope;
    }

    /** Keep base projections and cleanup independent of unavailable Media services. */
    private function registerAttachmentAdapters(): void
    {
        $this->app->bindIf(CommentAttachmentLifecycle::class, static function (Application $app): CommentAttachmentLifecycle {
            $app->make(OptionalIntegration::class)->enabled('comments.attachments.enabled', MediaServiceProvider::class);

            return ($app->getLoadedProviders()[MediaServiceProvider::class] ?? false) === true
                ? $app->make(MediaCommentAttachmentLifecycle::class)
                : new UnavailableCommentAttachments;
        });
    }

    private function registerTargets(CommentTargetRegistry $registry): void
    {
        $configured = config('nvl-comments.targets', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('nvl-comments.targets must be an array.');
        }

        foreach ($configured as $alias => $resolver) {
            $owner = null;

            if (is_array($resolver)) {
                $owner = $resolver['owner'] ?? null;
                $resolver = $resolver['resolver'] ?? null;

                if (! is_string($owner)) {
                    throw new InvalidArgumentException('Every comments identity reference must declare an owner alias.');
                }
            }

            if (! is_string($alias)
                || ! is_string($resolver)
                || ! is_a($resolver, CommentTargetResolver::class, true)) {
                throw new InvalidArgumentException('Every configured comment target is invalid.');
            }

            $registry->register($alias, $resolver, $owner);
        }
    }

    /**
     * Bind one configurable package contract after validating its implementation.
     *
     * @param  class-string  $contract
     * @param  class-string  $default
     */
    private function bindConfiguredContract(
        string $contract,
        string $configurationKey,
        string $default,
    ): void {
        $implementation = config($configurationKey, $default);

        if (! is_string($implementation)
            || ! is_a($implementation, $contract, true)) {
            throw new InvalidArgumentException(
                "{$configurationKey} must implement {$contract}.",
            );
        }

        $this->app->bindIf($contract, $implementation);
    }
}
