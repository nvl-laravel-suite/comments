<?php

declare(strict_types=1);

namespace Nvl\Comments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReaction;
use Nvl\Comments\Services\CommentModelRegistry;

/**
 * Builds CommentReaction fixture rows and their declared package parents.
 *
 * @extends Factory<CommentReaction>
 *
 * @api
 */
final class CommentReactionFactory extends Factory
{
    protected $model = CommentReaction::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (CommentReaction $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $owner = FactoryGuard::owner($model, 'actor_type', 'actor_id');
            if ($model->getAttribute('comment_id') !== null) {
                $parent = CommentModelRegistry::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('comment_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<CommentReaction>, mixed>
     */
    public function definition(): array
    {
        return [
            'comment_id' => Comment::factory(),
            'actor_type' => null,
            'actor_id' => null,
            'type' => 'like',
        ];
    }

    /**
     * Associate an admitted persisted Comment parent.
     *
     * @api
     */
    public function forComment(Comment $parent): static
    {
        FactoryGuard::parent($parent, new CommentReaction);

        return $this->state([
            'comment_id' => $parent->getKey(),
        ]);
    }

    /**
     * Associate a persisted host owner using its native morph identity.
     *
     * @api
     */
    public function forOwner(Model $owner): static
    {
        FactoryGuard::parent($owner, new CommentReaction);

        return $this->state([
            'actor_type' => $owner->getMorphClass(),
            'actor_id' => (string) FactoryGuard::identifier($owner->getKey()),
        ]);
    }
}
