<?php

declare(strict_types=1);

namespace Nvl\Comments\Tenancy;

use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMention;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers canonical target-derived ownership for the whole discussion graph. */
final readonly class CommentsResourceRegistrar
{
    public function register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoption): void
    {
        $resources->requireCompatible('comments', 'media');
        $resources->registerParentResolver('comments.comments', CommentTenantParentResolver::class);
        foreach ([
            new TenantResourceDefinition('comments.comments', 'comments', Comment::class, TenantResourceKind::Inherited, null, 'commentable'),
            new TenantResourceDefinition('comments.reactions', 'comments', CommentReaction::class, TenantResourceKind::Inherited, 'comments.comments', 'comment'),
            new TenantResourceDefinition('comments.revisions', 'comments', CommentRevision::class, TenantResourceKind::Inherited, 'comments.comments', 'comment'),
            new TenantResourceDefinition('comments.reports', 'comments', CommentReport::class, TenantResourceKind::Inherited, 'comments.comments', 'comment'),
            new TenantResourceDefinition('comments.metadata', 'comments', CommentMetadataValue::class, TenantResourceKind::Inherited, 'comments.comments', 'comment'),
            new TenantResourceDefinition('comments.mentions', 'comments', CommentMention::class, TenantResourceKind::Inherited, 'comments.comments', 'comment'),
        ] as $resource) {
            $resources->register($resource);
        }
        $adoption->register('comments', CommentsAdoptionAdapter::class);
    }
}
