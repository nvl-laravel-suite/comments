<?php

declare(strict_types=1);

namespace Nvl\Comments\Ownership;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

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
        return self::declared() || config('nvl-tenancy.enabled') === true;
    }

    public static function resource(Model $model): string
    {
        return self::declared() ? app(OwnershipResourceRegistry::class)->forModel($model)->key : app(TenantResourceRegistry::class)->forModel($model)->key;
    }

    /** Capture scalar admission; untouched graphs preserve disabled installation behavior. */
    public static function envelope(): OwnershipJobEnvelope
    {
        $context = app(OwnershipContext::class);
        if ($context->snapshot()->mode === OwnershipContextMode::Unresolved) {
            foreach (app(OwnershipResourceRegistry::class)->all() as $resource) {
                if ($resource->ownership->strategy !== 'unscoped') {
                    return OwnershipJobEnvelope::capture($context);
                }
            }

            return OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled));
        }

        return OwnershipJobEnvelope::capture($context);
    }

    /** Bind signed capabilities to the complete owner and generation. */
    public static function capability(): ?string
    {
        if (self::declared()) {
            return app(OwnershipBoundary::class)->key('comments.comments', 'attachment-url');
        }
        $snapshot = app(TenantContext::class)->snapshot();

        return $snapshot->mode === TenantContextMode::Disabled ? null : hash('sha256', $snapshot->mode->value."\0".($snapshot->tenantId !== null ? $snapshot->tenantId->value : ''));
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
