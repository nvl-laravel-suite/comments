<?php

declare(strict_types=1);

namespace Nvl\Comments\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Morph-to relation that keeps target models on their configured connection.
 *
 * @template TDeclaringModel of Model
 *
 * @extends MorphTo<Model, TDeclaringModel>
 */
final class CommentTargetMorphTo extends MorphTo
{
    /**
     * Create a target model without inheriting the Comments connection.
     *
     * @param  string  $type
     */
    public function createModelByType($type): Model
    {
        $modelClass = Model::getActualClassNameForMorph($type);

        if (! is_a($modelClass, Model::class, true)) {
            throw new LogicException(
                "Comment target morph type [{$type}] is not an Eloquent model.",
            );
        }

        return new $modelClass;
    }
}
