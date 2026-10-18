<?php

declare(strict_types=1);

namespace Nvl\Comments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentModelRegistry;

/**
 * Builds CommentRevision fixture rows and their declared package parents.
 *
 * @extends Factory<CommentRevision>
 *
 * @api
 */
final class CommentRevisionFactory extends Factory
{
    protected $model = CommentRevision::class;

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
        })->afterMaking(function (CommentRevision $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('comment_id') !== null) {
                $parent = CommentModelRegistry::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('comment_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
                $model->forceFill([
                    'revision' => $parent->revision,
                    'body' => $parent->body,
                    'format' => $parent->format,
                    'locale' => $parent->locale,
                    'tags' => $parent->tags,
                    'metadata' => $parent->metadata,
                    'document' => $parent->document,
                ]);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<CommentRevision>, mixed>
     */
    public function definition(): array
    {
        return [
            'comment_id' => Comment::factory(),
            'revision' => 1,
            'body' => $this->faker->sentence(),
            'format' => 'plain',
        ];
    }

    /**
     * Associate an admitted persisted Comment parent.
     *
     * @api
     */
    public function forComment(Comment $parent): static
    {
        FactoryGuard::parent($parent, new CommentRevision);

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
