<?php

declare(strict_types=1);

namespace Nvl\Comments\Data\Mutations;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Exact client-owned inline node union accepted by version-one rich mutations.
 */
#[TypeScript]
#[LiteralTypeScriptType("{ type: 'text'; text: string } | { type: 'hard_break' } | { type: 'mention'; tokenId: string; resource: string; id: string | number }")]
final class CommentDocumentNodeData extends Data {}
