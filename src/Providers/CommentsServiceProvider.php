<?php

declare(strict_types=1);

namespace Nvl\Comments\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\DetachCommentMediaAction;
use Nvl\Comments\Console\CommentsDoctorCommand;
use Nvl\Comments\Console\CommentsReconcileCommand;
use Nvl\Comments\Contracts\CommentActorResolver;
use Nvl\Comments\Contracts\CommentAttachmentLifecycle;
use Nvl\Comments\Contracts\CommentAttachmentProjection;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentAuthorPresenter;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Contracts\CommentTargetResolver;
use Nvl\Comments\Integrations\MediaCommentAttachmentLifecycle;
use Nvl\Comments\Integrations\MediaCommentAttachmentProjection;
use Nvl\Comments\Integrations\MediaCommentRelations;
use Nvl\Comments\Integrations\UnavailableCommentAttachments;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentsDoctor;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;
use Nvl\Comments\Services\SafeCommentAuthorPresenter;
use Nvl\Comments\Support\CommentActorFactory;
use Nvl\Comments\Tenancy\CommentsResourceRegistrar;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Integrations\OptionalIntegration;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Providers\TenantServiceProvider;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

/**
 * Registers the standalone, headless Comments package.
 */
final class CommentsServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    public function register(): void
    {
        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/comments', fn (): array => PackageDoctorContributor::reportChecks($this->app->make(CommentsDoctor::class)->inspect(), 'nvl:comments:doctor'));

        $this->app->register(TenantServiceProvider::class);
        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-comments.php', 'comments');
        $this->registerAttachmentAdapters();
        (new CommentsResourceRegistrar)->register($this->app->make(TenantResourceRegistry::class));
        $this->app->booted(function (): void {
            if ($this->app->bound(TenantAdoptionRegistry::class)) {
                (new CommentsResourceRegistrar)->register($this->app->make(TenantResourceRegistry::class), $this->app->make(TenantAdoptionRegistry::class));
            }
        });
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
        $this->bindConfiguredContract(
            CommentActorResolver::class,
            'nvl-comments.actor_resolver.class',
            CommentActorFactory::class,
        );
        $this->bindConfiguredContract(
            CommentAuthorPresenter::class,
            'nvl-comments.author_presenter.class',
            SafeCommentAuthorPresenter::class,
        );
        $this->app->alias(
            'db.transactions',
            DatabaseTransactionsManager::class,
        );
        $this->app->scoped(CommentMutationLock::class);
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
        TypeScriptSourceRegistry $typeScriptSources,
        CommentTargetRegistry $targets,
        CommentMetadataRegistry $metadata,
        Dispatcher $events,
    ): void {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/comments');
        $this->registerTargets($targets);
        if ($this->app->make(OptionalIntegration::class)->enabled('comments.attachments.enabled', MediaServiceProvider::class)) {
            $this->app->make(TenantResourceRegistry::class)->requireCompatible('comments', 'media');
        }
        Comment::resolveRelationUsing('mediaAssociations', function (Comment $comment) {
            if (($this->app->getLoadedProviders()[MediaServiceProvider::class] ?? false) !== true) {
                throw new InvalidArgumentException('Comment attachment relationships require the loaded Media provider.');
            }

            return MediaCommentRelations::associations($comment);
        });
        Comment::resolveRelationUsing('media', function (Comment $comment) {
            if (($this->app->getLoadedProviders()[MediaServiceProvider::class] ?? false) !== true) {
                throw new InvalidArgumentException('Comment attachment relationships require the loaded Media provider.');
            }

            return MediaCommentRelations::media($comment);
        });
        Comment::resolveRelationUsing('attachmentAssociations', function (Comment $comment) {
            if (($this->app->getLoadedProviders()[MediaServiceProvider::class] ?? false) !== true) {
                throw new InvalidArgumentException('Comment attachment relationships require the loaded Media provider.');
            }

            return MediaCommentRelations::associations($comment)->where('collection', 'attachments')->where('is_active', true);
        });
        Comment::deleting(function (Comment $comment): void {
            if ($comment->isForceDeleting() && ($this->app->getLoadedProviders()[MediaServiceProvider::class] ?? false) === true) {
                $this->app->make(MediaCommentAttachmentLifecycle::class)->deleteOwnerMedia($comment);
            }
        });
        $this->registerTransactionListeners($events);

        if (config('nvl-comments.migrations.enabled', true) === true) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CommentsDoctorCommand::class,
                CommentsReconcileCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../../config/nvl-comments.php' => config_path('nvl-comments.php'),
        ], 'comments-config');
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'comments-migrations');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'comments-skills');
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

    /** Keep base projections and cleanup independent of unavailable Media services. */
    private function registerAttachmentAdapters(): void
    {
        $this->app->bind(CommentAttachmentProjection::class, static function (Application $app): CommentAttachmentProjection {
            return $app->make(OptionalIntegration::class)->enabled('comments.attachments.enabled', MediaServiceProvider::class)
                ? $app->make(MediaCommentAttachmentProjection::class)
                : new UnavailableCommentAttachments;
        });
        $this->app->bind(CommentAttachmentLifecycle::class, static function (Application $app): CommentAttachmentLifecycle {
            $app->make(OptionalIntegration::class)->enabled('comments.attachments.enabled', MediaServiceProvider::class);

            return ($app->getLoadedProviders()[MediaServiceProvider::class] ?? false) === true
                ? $app->make(MediaCommentAttachmentLifecycle::class)
                : new UnavailableCommentAttachments;
        });
        foreach ([AttachCommentMediaAction::class, DetachCommentMediaAction::class] as $action) {
            $this->app->bind($action, static function (Application $app) use ($action): object {
                $app->make(OptionalIntegration::class)->enabled('comments.attachments.enabled', MediaServiceProvider::class, requested: true);

                return $app->build($action);
            });
        }
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
