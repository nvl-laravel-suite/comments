<?php

declare(strict_types=1);

namespace Nvl\Comments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentReport;
use Nvl\Comments\Services\CommentModelRegistry;

/**
 * Builds CommentReport fixture rows and their declared package parents.
 *
 * @extends Factory<CommentReport>
 *
 * @api
 */
final class CommentReportFactory extends Factory
{
    protected $model = CommentReport::class;

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
        })->afterMaking(function (CommentReport $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $owner = FactoryGuard::owner($model, 'reporter_type', 'reporter_id');
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
     * @return array<model-property<CommentReport>, mixed>
     */
    public function definition(): array
    {
        return [
            'comment_id' => Comment::factory(),
            'reporter_type' => null,
            'reporter_id' => null,
            'reason' => 'spam',
            'status' => 'open',
        ];
    }

    /**
     * Associate an admitted persisted Comment parent.
     *
     * @api
     */
    public function forComment(Comment $parent): static
    {
        FactoryGuard::parent($parent, new CommentReport);

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
        FactoryGuard::parent($owner, new CommentReport);

        return $this->state([
            'reporter_type' => $owner->getMorphClass(),
            'reporter_id' => (string) FactoryGuard::identifier($owner->getKey()),
        ]);
    }
}
