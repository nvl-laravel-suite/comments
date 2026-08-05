<?php

declare(strict_types=1);

namespace Nvl\Comments\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stable capabilities protected by the comment policy boundary.
 */
#[TypeScript]
enum CommentAbility: string
{
    case List = 'list';
    case View = 'view';
    case ViewIdentity = 'view_identity';
    case Create = 'create';
    case Reply = 'reply';
    case Update = 'update';
    case Delete = 'delete';
    case Restore = 'restore';
    case Anonymize = 'anonymize';
    case React = 'react';
    case Report = 'report';
    case Attach = 'attach';
    case Detach = 'detach';
    case ViewHistory = 'view_history';
    case RestoreRevision = 'restore_revision';
    case Moderate = 'moderate';
}
