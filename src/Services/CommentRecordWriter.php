<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;

/** Persists package children with server-assigned canonical partitions. @internal */
final readonly class CommentRecordWriter
{
    /** Save new reports through their canonical assignment and retain native veto behavior. */
    public static function persist(Model $model): bool
    {
        if ($model->exists) {
            return $model->save();
        }

        return app(OwnershipWriter::class)->create($model::class, static function (array $ownership) use ($model): Model {
            if (! $model->forceFill($ownership)->save()) {
                throw new InvalidCommentMutationException('The comment report could not be saved.');
            }

            return $model;
        })->exists;
    }

    /** @template T of Model
     * @param  class-string<T>  $class
     * @param  array<string,mixed>  $attributes
     * @return T
     */
    public static function create(string $class, array $attributes, bool $restoring = false): Model
    {
        return app(OwnershipWriter::class)->create($class, static function (array $ownership) use ($class, $attributes, $restoring): Model {
            $model = new $class;
            if (! $model->forceFill([...$attributes, ...$ownership])->save()) {
                if ($restoring) {
                    throw new InvalidCommentLifecycleException('The current comment revision could not be preserved.');
                }
                throw new InvalidCommentMutationException('The comment '.(str_ends_with($class, 'Revision') ? 'revision could not be saved; the current revision could not be preserved' : 'reaction could not be created').'.');
            }

            return $model;
        });
    }
}
