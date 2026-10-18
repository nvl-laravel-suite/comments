<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Models\Comment;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipScope;
use ReflectionMethod;

/** Resolves the one configured Comment extension through the shared native model identity. @api */
final class CommentModelRegistry
{
    /** @var class-string<Comment>|null */
    private ?string $comment = null;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(private readonly Repository $configuration, private readonly OwnerRegistry $identities) {}

    /** Resolve and freeze the validated concrete Comment class before resource compilation.
     * @return class-string<Comment>
     */
    public function commentClass(): string
    {
        if ($this->comment !== null) {
            return $this->comment;
        }
        $configured = $this->configuration->get('nvl-comments.models.comment', Comment::class);
        if (! is_string($configured)) {
            throw new OwnershipConfigurationInvalid('Comment model configuration must name a concrete Comment subclass.');
        }
        $class = (new ConfiguredModelResolver)->resolve($configured, Comment::class);
        if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'inherited'], true)) {
            throw new OwnershipConfigurationInvalid('Comments require unscoped storage or canonical target inheritance.');
        }
        if ($class::ownershipDefinition()->strategy === 'inherited' && $class::ownershipDefinition()->relation !== 'commentable') {
            throw new OwnershipConfigurationInvalid('Comments must inherit their canonical target.');
        }
        $model = new $class;
        $base = new Comment;
        foreach ($base->getCasts() as $attribute => $cast) {
            if (($model->getCasts()[$attribute] ?? null) !== $cast) {
                throw new OwnershipConfigurationInvalid('Configured Comment models must retain the required casts.');
            }
        }
        foreach (['save', 'delete', 'newQueryForRestoration', 'bootHasOwnership'] as $method) {
            if ((new ReflectionMethod($class, $method))->getDeclaringClass()->getName() !== Comment::class) {
                throw new OwnershipConfigurationInvalid('Configured Comment models must retain the canonical persistence hooks.');
            }
        }
        if (! $model->hasGlobalScope(OwnershipScope::class)
            || $model->getKeyType() !== 'string' || $model->getIncrementing()) {
            throw new OwnershipConfigurationInvalid('Configured Comment models must retain the builder, UUID and ownership capabilities.');
        }
        $this->identities->registerReplacement('comments.comments', Comment::class, $class);
        $this->comment = $class;

        return $class;
    }

    /** Resolve the immutable model from native framework entry points. @internal
     * @return class-string<Comment>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->commentClass();
    }

    /** Construct the exact configured class without retaining a request-scoped model. @internal */
    public static function newInstance(): Comment
    {
        $class = Container::getInstance()->make(self::class)->commentClass();

        return new $class;
    }

    /** Persist a package-authored attribute map under the exact source assignment. @internal
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

    /** Create the canonical configured query. @internal
     * @return Builder<Comment>
     */
    public static function query(): Builder
    {
        return self::newInstance()->newQuery();
    }
}
