<?php

declare(strict_types=1);

use Nvl\Comments\Services\CommentsDoctor;

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
