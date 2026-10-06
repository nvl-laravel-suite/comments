<?php

declare(strict_types=1);

namespace Nvl\Comments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentMention;

/**
 * Builds CommentMention fixture rows and their declared package parents.
 *
 * @extends Factory<CommentMention>
 *
 * @api
 */
final class CommentMentionFactory extends Factory
{
    protected $model = CommentMention::class;

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
        })->afterMaking(function (CommentMention $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('comment_id') !== null) {
                $parent = Comment::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('comment_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<CommentMention>, mixed>
     */
    public function definition(): array
    {
        return [
            'comment_id' => Comment::factory(),
            'token_id' => $this->faker->uuid(),
            'resource_alias' => 'fixture',
            'resource_id' => $this->faker->uuid(),
            'resource_identity_hash' => fn (array $attributes): string => hash('sha256', "comment-mention-resource\0".(string) FactoryGuard::identifier($attributes['resource_alias'])."\0".(string) FactoryGuard::identifier($attributes['resource_id'])),
            'label_snapshot' => $this->faker->word(),
            'position' => 0,
        ];
    }

    /**
     * Associate an admitted persisted Comment parent.
     *
     * @api
     */
    public function forComment(Comment $parent): static
    {
        FactoryGuard::parent($parent, new CommentMention);

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

    /** Associate an explicit normalized resource identity and label.
     *
     * @api
     */
    public function forResource(string $alias, string $identifier, string $label): static
    {
        return $this->state([
            'resource_alias' => $alias,
            'resource_id' => $identifier,
            'resource_identity_hash' => hash('sha256', "comment-mention-resource\0{$alias}\0{$identifier}"),
            'label_snapshot' => $label,
        ]);
    }
}
