<?php

declare(strict_types=1);

use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMention;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantResourceRegistry;

it('registers target-derived ownership for every independently queried comment row', function (): void {
    $resources = app(TenantResourceRegistry::class);

    expect($resources->get('comments.comments')->model)->toBe(Comment::class)
        ->and($resources->get('comments.comments')->kind)->toBe(TenantResourceKind::Inherited)
        ->and($resources->get('comments.reactions')->model)->toBe(CommentReaction::class)
        ->and($resources->get('comments.revisions')->model)->toBe(CommentRevision::class)
        ->and($resources->get('comments.reports')->model)->toBe(CommentReport::class)
        ->and($resources->get('comments.metadata')->model)->toBe(CommentMetadataValue::class)
        ->and($resources->get('comments.mentions')->model)->toBe(CommentMention::class);
});

it('persists explicit tenant ownership on each discussion model', function (): void {
    foreach ([
        new Comment,
        new CommentReaction,
        new CommentRevision,
        new CommentReport,
        new CommentMetadataValue,
        new CommentMention,
    ] as $model) {
        expect($model->getFillable())->toContain('tenant_id');
    }
});
