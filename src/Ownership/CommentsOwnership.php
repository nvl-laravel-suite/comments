<?php

declare(strict_types=1);

namespace Nvl\Comments\Ownership;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Compatibility\LegacyOwnershipSupport;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueCapture;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Resolves canonical metadata without retaining request admission.
 *
 * @internal
 */
final class CommentsOwnership
{
    public static function declared(): bool
    {
        return (app(OwnershipResourceRegistry::class)->all()['comments.comments'] ?? null)?->ownership->strategy !== null && app(OwnershipResourceRegistry::class)->get('comments.comments')->ownership->strategy !== 'unscoped';
    }

    public static function active(): bool
    {
        return self::declared() || app(LegacyOwnershipSupport::class)->enabled();
    }

    public static function resource(Model $model): string
    {
        return self::declared() ? app(OwnershipResourceRegistry::class)->forModel($model)->key : app(LegacyOwnershipSupport::class)->resource($model);
    }

    /** Capture scalar admission; untouched graphs preserve disabled installation behavior. */
    public static function envelope(): OwnershipJobEnvelope
    {
        return app(OwnershipQueueCapture::class)->capture();
    }

    /** Bind signed capabilities to the complete owner and generation. */
    public static function capability(): ?string
    {
        if (self::declared()) {
            return app(OwnershipBoundary::class)->key('comments.comments', 'attachment-url');
        }

        return app(LegacyOwnershipSupport::class)->capability();
    }

    /** Owned discussions cannot attach or project a globally accessible asset graph. */
    public static function assertAttachmentSupport(): void
    {
        if (self::declared() && app(OwnershipResourceRegistry::class)->get('media.assets')->ownership->strategy === 'unscoped') {
            throw new OwnershipViolation('Owned Comments attachments require an owned Media graph.');
        }
    }

    private function __construct() {}
}
