<?php

declare(strict_types=1);

namespace Nvl\Comments\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Comments\Console\CommentsDoctorCommand;
use Nvl\Comments\Console\CommentsReconcileCommand;
use Nvl\Comments\Contracts\CommentActorResolver;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentAuthorPresenter;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Contracts\CommentTargetResolver;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentMutationLock;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;
use Nvl\Comments\Services\SafeCommentAuthorPresenter;
use Nvl\Comments\Support\CommentActorFactory;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;

/**
 * Registers the standalone, headless Comments package.
 */
final class CommentsServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    public function register(): void
    {
        $this->mergePackageConfiguration(__DIR__.'/../../config/comments.php', CommentsTables::Comments);
        $authorization = config(
            'comments.authorization.class',
            ConfiguredCommentAuthorization::class,
        );

        if (! is_string($authorization)
            || ! is_a($authorization, CommentAuthorization::class, true)) {
            throw new InvalidArgumentException(
                'comments.authorization.class must implement CommentAuthorization.',
            );
        }

        $this->app->bindIf(CommentAuthorization::class, $authorization);
        $this->bindConfiguredContract(
            CommentQueryScope::class,
            'comments.query_scope.class',
            $authorization,
        );
        $this->bindConfiguredContract(
            CommentActorResolver::class,
            'comments.actor_resolver.class',
            CommentActorFactory::class,
        );
        $this->bindConfiguredContract(
            CommentAuthorPresenter::class,
            'comments.author_presenter.class',
            SafeCommentAuthorPresenter::class,
        );
        $this->app->alias(
            'db.transactions',
            DatabaseTransactionsManager::class,
        );
        $this->app->scoped(CommentMutationLock::class);
        $this->app->singleton(CommentMetadataRegistry::class);
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
        $this->registerTransactionListeners($events);

        if (config('comments.migrations.enabled', true) === true) {
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
            __DIR__.'/../../config/comments.php' => config_path('comments.php'),
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

    private function registerTargets(CommentTargetRegistry $registry): void
    {
        $configured = config('comments.targets', []);

        if (! is_array($configured)) {
            throw new InvalidArgumentException('comments.targets must be an array.');
        }

        foreach ($configured as $alias => $resolver) {
            if (! is_string($alias)
                || ! is_string($resolver)
                || ! is_a($resolver, CommentTargetResolver::class, true)) {
                throw new InvalidArgumentException('Every configured comment target is invalid.');
            }

            $registry->register($alias, $resolver);
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
