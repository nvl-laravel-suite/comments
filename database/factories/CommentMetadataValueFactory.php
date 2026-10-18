<?php

declare(strict_types=1);

namespace Nvl\Comments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMetadataValue;
use Nvl\Comments\Services\CommentModelRegistry;

/**
 * Builds CommentMetadataValue fixture rows and their declared package parents.
 *
 * @extends Factory<CommentMetadataValue>
 *
 * @api
 */
final class CommentMetadataValueFactory extends Factory
{
    protected $model = CommentMetadataValue::class;

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
        })->afterMaking(function (CommentMetadataValue $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

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
     * @return array<model-property<CommentMetadataValue>, mixed>
     */
    public function definition(): array
    {
        return [
            'comment_id' => Comment::factory(),
            'schema_namespace' => 'factory',
            'field_name' => 'label',
            'value_type' => 'string',
            'value_hash' => hash('sha256', $this->faker->word()),
        ];
    }

    /**
     * Associate an admitted persisted Comment parent.
     *
     * @api
     */
    public function forComment(Comment $parent): static
    {
        FactoryGuard::parent($parent, new CommentMetadataValue);

        return $this->state([
            'comment_id' => $parent->getKey(),
        ]);
    }

    /** Build a comment parent for an explicitly persisted target owner.
     *
     * @api
     */
    public function forOwner(Model $owner): static
    {
        return $this->state(['comment_id' => Comment::factory()->forOwner($owner)]);
    }
}
