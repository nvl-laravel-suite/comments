<?php

declare(strict_types=1);

namespace Nvl\Comments\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Exact viewer-safe inline node union projected from stored rich documents.
 */
#[TypeScript]
#[LiteralTypeScriptType("{ type: 'text'; text: string } | { type: 'hard_break' } | { type: 'mention'; tokenId: string; resource: string; state: Nvl.Comments.Enums.CommentMentionState; label: string }")]
final class CommentViewerDocumentNodeData extends Data {}
