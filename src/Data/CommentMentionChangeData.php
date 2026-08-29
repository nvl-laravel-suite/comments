<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Privacy-bounded mention identity included in durable change events.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CommentMentionChangeData extends Data
{
    use DataTransform;

    /**
     * Create one bounded mention change fact.
     */
    public function __construct(
        public readonly string $resourceAlias,
        public readonly string $resourceId,
        public readonly string $tokenId,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $this->resourceAlias) !== 1
            || ! mb_check_encoding($this->resourceId, 'UTF-8')
            || preg_match('/\S/u', $this->resourceId) !== 1
            || mb_strlen($this->resourceId) > 255
            || ! Str::isUuid($this->tokenId)) {
            throw new InvalidArgumentException('Comment mention change facts are invalid.');
        }
    }
}
