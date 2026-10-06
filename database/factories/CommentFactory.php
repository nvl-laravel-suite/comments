<?php

declare(strict_types=1);

namespace Nvl\Comments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Models\Comment;

/**
 * Builds Comment fixture rows and their declared package parents.
 *
 * @extends Factory<Comment>
 *
 * @api
 */
final class CommentFactory extends Factory
{
    protected $model = Comment::class;

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
        })->afterMaking(function (Comment $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $owner = FactoryGuard::owner($model, 'commentable_type', 'commentable_id');
            FactoryGuard::inherit($model, $owner);
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<Comment>, mixed>
     */
    public function definition(): array
    {
        return [
            'commentable_type' => null,
            'commentable_id' => null,
            'body' => $this->faker->sentence(),
            'format' => 'plain',
            'status' => 'pending',
            'visibility' => 'public',
            'revision' => 1,
        ];
    }

    /**
     * Associate a persisted host owner using its native morph identity.
     *
     * @api
     */
    public function forOwner(Model $owner): static
    {
        FactoryGuard::parent($owner, new Comment);

        return $this->state([
            'commentable_type' => $owner->getMorphClass(),
            'commentable_id' => (string) FactoryGuard::identifier($owner->getKey()),
        ]);
    }
}
