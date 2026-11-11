<?php

declare(strict_types=1);

use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Exceptions\CommentMutationLockConfigurationException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMention;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentsDoctor;
use Nvl\Comments\Support\CommentMutationLockConfiguration;

it('uses the published dedicated mutation lock store', function (): void {
    config(['nvl-comments.locks.mutation.store' => 'dedicated-locks']);

    expect(CommentMutationLockConfiguration::settings()['store'])->toBe('dedicated-locks');
});

it('rejects invalid published mutation lock stores', function (mixed $store): void {
    config(['nvl-comments.locks.mutation.store' => $store]);

    expect(fn (): array => CommentMutationLockConfiguration::settings())
        ->toThrow(CommentMutationLockConfigurationException::class);
})->with([false, 5, ' ', [[]]]);

it('inspects the native package without HTTP route or DTO bindings', function (): void {
    $checks = app(CommentsDoctor::class)->inspect();

    expect($checks)->toHaveKey('query_scope.ready');
    expect($checks['query_scope.ready'])->toBeTrue();
    expect($checks['healthy'])->toBeTrue();
    expect($checks)->not->toHaveKey('actor_resolver.ready');
    expect($checks)->not->toHaveKey('routes.public_ready');
});

it('renders the native doctor report and honors strict readiness', function (): void {
    $this->artisan('nvl:comments:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertSuccessful();
});

it('reports content limits above the body column capacity as invalid configuration', function (string $key, string $check): void {
    config([$key => 65_535]);
    expect(app(CommentsDoctor::class)->inspect()[$check])->toBeTrue();

    config([$key => 65_536]);
    $checks = app(CommentsDoctor::class)->inspect();

    expect($checks[$check])->toBeFalse()->and($checks['healthy'])->toBeFalse();
})->with([
    'plain content' => ['nvl-comments.content.maximum_bytes', 'configuration.values'],
    'rich documents' => ['nvl-comments.rich_text.maximum_bytes', 'rich_text.bounds_ready'],
]);

it('publishes every package table and no setting that nothing reads', function (): void {
    config(['nvl-comments.tables.mentions' => 'host_comment_mentions', 'nvl-comments.tables.metadata_values' => 'host_comment_metadata']);

    expect(array_keys((require dirname(__DIR__, 2).'/config/nvl-comments.php')['tables']))->toBe(['comments', 'reactions', 'revisions', 'reports', 'metadata_values', 'mentions'])
        ->and((new CommentMention)->getTable())->toBe('host_comment_mentions')
        ->and((new CommentMetadataValue)->getTable())->toBe('host_comment_metadata')
        ->and(app(CommentsDoctor::class)->inspect()['index.'.CommentsTables::Comments.'.target_pair'])->toBeTrue()
        ->and(collect([Comment::class, CommentMention::class, CommentMetadataValue::class, CommentReaction::class, CommentReport::class, CommentRevision::class])
            ->flatMap(static fn (string $model): array => (new $model)->getFillable())->all())->not->toContain('tenant_id')
        ->and(config('nvl-comments.moderation'))->not->toHaveKey('actionable_statuses')
        ->and(config('nvl-comments.attachments'))->not->toHaveKey('signed_url_lifetime');
});
