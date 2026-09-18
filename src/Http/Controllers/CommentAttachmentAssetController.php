<?php

declare(strict_types=1);

namespace Nvl\Comments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentAttachmentAssetResponder;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaConfiguredVariationService;
use Nvl\Media\Services\MediaQueryService;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves signed association-scoped assets without placing Media internals in URLs.
 */
final class CommentAttachmentAssetController extends Controller
{
    public function __construct(
        private readonly CommentAttachmentAssetResponder $assets,
        private readonly MediaQueryService $mediaQueries,
        private readonly MediaConfiguredVariationService $variations,
        private readonly TenantContext $context,
    ) {}

    /**
     * Serve the original attachment through a short-lived signed URL.
     */
    public function asset(
        Request $request,
        string $association,
    ): BinaryFileResponse|StreamedResponse|Response {
        return $this->serve($request, $association, thumbnail: false);
    }

    /**
     * Serve the preferred thumbnail, falling back safely to the original asset.
     */
    public function thumbnail(
        Request $request,
        string $association,
    ): BinaryFileResponse|StreamedResponse|Response {
        return $this->serve($request, $association, thumbnail: true);
    }

    /**
     * Resolve an active attachment association and delegate secure binary delivery to Media.
     */
    private function serve(
        Request $request,
        string $associationId,
        bool $thumbnail,
    ): BinaryFileResponse|StreamedResponse|Response {
        $snapshot = $this->context->snapshot();
        if ($snapshot->mode !== TenantContextMode::Disabled) {
            $expected = hash(
                'sha256',
                $snapshot->mode->value."\0".($snapshot->tenantId !== null ? $snapshot->tenantId->value : ''),
            );
            abort_unless(hash_equals($expected, (string) $request->query('partition')), 404);
        }
        $association = $this->mediaQueries->activeAssociation(
            $associationId,
            (new Comment)->getMorphClass(),
            'attachments',
        );
        $media = $association->getRelation('media');

        abort_unless($media instanceof Media && $media->isAvailable(), 404);

        $variationLabel = null;

        if ($thumbnail && $media->type->supportsConversions()) {
            $label = $this->variations->preferredPreviewVariationLabel();

            if ($label !== null && $media->hasVariation($label)) {
                $variationLabel = $label;
            }
        }

        $response = $this->assets->serve($request, $media, $variationLabel);

        $response->headers->set(
            'Cache-Control',
            'private, no-store, max-age=0',
        );

        return $response;
    }
}
