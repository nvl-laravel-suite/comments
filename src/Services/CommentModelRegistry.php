<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Support\Models\ConfiguredModelRegistration;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Resolves the one configured Comment extension through the shared native model identity.
 *
 * @api
 */
final class CommentModelRegistry
{
    /** @var ConfiguredModelRegistration<Comment> */
    private readonly ConfiguredModelRegistration $registration;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(
        Repository $configuration,
        OwnerRegistry $identities,
    ) {
        $this->registration = new ConfiguredModelRegistration(
            $configuration,
            $identities,
            'nvl-comments.models.comment',
            Comment::class,
            'comments.comments',
            static function (string $class): void {
                if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'inherited'], true)) {
                    throw new OwnershipConfigurationInvalid('Comments require unscoped storage or canonical target inheritance.');
                }
                if ($class::ownershipDefinition()->strategy === 'inherited' && $class::ownershipDefinition()->relation !== 'commentable') {
                    throw new OwnershipConfigurationInvalid('Comments must inherit their canonical target.');
                }
            },
        );
    }

    /** Resolve and freeze the concrete Comment class before resource compilation.
     * @internal
     *
     * @return class-string<Comment>
     */
    public function prepare(): string
    {
        return $this->registration->prepare();
    }

    /** Validate native capabilities after Laravel installs Eloquent's dispatcher.
     * @return class-string<Comment>
     */
    public function commentClass(): string
    {
        return $this->registration->modelClass();
    }

    /** Resolve the immutable model from native framework entry points.
     * @internal
     *
     * @return class-string<Comment>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->commentClass();
    }

    /**
     * Construct the exact configured class without retaining a request-scoped model.
     *
     * @internal
     */
    public static function newInstance(): Comment
    {
        $class = Container::getInstance()->make(self::class)->commentClass();

        return new $class;
    }

    /** Persist a package-authored attribute map under the exact source assignment.
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes): Comment
    {
        return app(OwnershipWriter::class)->create(self::modelClass(), static function (array $ownership) use ($attributes): Comment {
            $model = self::newInstance();
            if (! $model->forceFill([...$attributes, ...$ownership])->save()) {
                throw new InvalidCommentMutationException('The comment could not be created.');
            }

            return $model;
        });
    }

    /** Create the canonical configured query.
     * @internal
     *
     * @return Builder<Comment>
     */
    public static function query(): Builder
    {
        return self::newInstance()->newQuery();
    }
}
