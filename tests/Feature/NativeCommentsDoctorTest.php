<?php

declare(strict_types=1);

use Nvl\Comments\Exceptions\CommentMutationLockConfigurationException;
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
