<?php

declare(strict_types=1);

namespace Nvl\Comments\Ownership;

use InvalidArgumentException;
use Nvl\Comments\Models\CommentMention;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentModelRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Compiles target-derived ownership for the complete discussion graph.
 *
 * @internal
 */
final readonly class CommentsResourceRegistrar
{
    public function __construct(private CommentModelRegistry $models) {}

    public function register(OwnershipResourceRegistry $resources): void
    {
        $class = $this->models->commentClass();
        $ownership = $class::ownershipDefinition();
        $owned = $ownership->strategy !== 'unscoped';
        $parents = config('nvl-comments.ownership.target_resources', []);
        if (! is_array($parents) || ! array_is_list($parents)) {
            throw new InvalidArgumentException('Comment target resources must be an explicit list.');
        }
        foreach ($parents as $parent) {
            if (! is_string($parent)) {
                throw new InvalidArgumentException('Comment target resources require canonical keys.');
            }
        }
        $resources->register(new OwnershipResourceDefinition('comments.comments', 'comments', $class, $ownership, parentResources: $owned ? $parents : [], immutableFields: $owned ? ['parent_id', 'root_id'] : []));
        foreach (['comments.reactions' => CommentReaction::class, 'comments.revisions' => CommentRevision::class, 'comments.reports' => CommentReport::class, 'comments.metadata' => CommentMetadataValue::class, 'comments.mentions' => CommentMention::class] as $key => $model) {
            $resources->register(new OwnershipResourceDefinition($key, 'comments', $model, $owned ? OwnershipDefinition::inherited('comment') : OwnershipDefinition::unscoped(), parentResource: $owned ? 'comments.comments' : null));
        }
    }
}
