<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\CommentAttachmentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Models\Comment;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaConfiguredVariationService;
use Nvl\Media\Services\MediaLocaleResolver;
use RuntimeException;

/**
 * Builds privacy-safe comment attachment projections from authorized Media associations.
 */
final readonly class CommentAttachmentDataFactory
{
    public function __construct(
        private CommentAuthorization $commentAuthorization,
        private CommentAttachmentUrlFactory $attachmentUrls,
        private MediaAuthorization $mediaAuthorization,
        private MediaConfiguredVariationService $configuredVariations,
        private MediaLocaleResolver $localeResolver,
    ) {}

    /**
     * Build one attachment DTO or fail closed when its Media cannot be viewed and delivered.
     */
    public function fromAssociation(
        MediaAssociation $association,
        Comment $comment,
        Model $target,
        CommentActorData $actor,
        CommentAudience $audience,
    ): ?CommentAttachmentData {
        if ($association->associable_type !== $comment->getMorphClass()
            || $association->associable_id !== $comment->id
            || $association->collection !== 'attachments'
            || ! $association->is_active
            || ! $association->relationLoaded('media')) {
            return null;
        }

        $media = $association->media;

        if (! $media instanceof Media || ! $media->isAvailable()) {
            return null;
        }

        $mediaActor = new MediaActorData($actor->type, $actor->id, $actor->system);

        if (! $this->mediaAuthorization->allows(
            $mediaActor,
            MediaAbility::View,
            $media,
            $comment,
        ) || ! $this->mediaAuthorization->allows(
            $mediaActor,
            MediaAbility::Download,
            $media,
            $comment,
        )) {
            return null;
        }

        try {
            $assetUrl = $this->attachmentUrls->asset($association);
        } catch (RuntimeException) {
            return null;
        }

        if ($assetUrl === '') {
            return null;
        }

        $locale = $audience === CommentAudience::Public
            ? $this->localeResolver->fallback()
            : $this->localeResolver->resolve();
        $title = $media->relationLoaded('translations')
            ? $this->translatedString($media, 'title', $locale)
            : null;
        $alt = $media->relationLoaded('translations')
            ? $this->translatedString($media, 'alt', $locale)
            : null;
        $canRemove = $this->commentAuthorization->allows(
            CommentAbility::Detach,
            $actor,
            $comment,
            $target,
            $audience,
        )
            && $this->mediaAuthorization->allows(
                $mediaActor,
                MediaAbility::Mutate,
                $media,
                $comment,
            );

        return new CommentAttachmentData(
            associationId: $association->id,
            kind: $media->type->value,
            name: $media->filename,
            mimeType: $media->mime_type,
            size: $media->size,
            title: $title,
            alt: $alt,
            assetUrl: $assetUrl,
            thumbnailUrl: $this->thumbnailUrl(
                $association,
                $media,
                $assetUrl,
            ),
            canRemove: $canRemove,
            createdAt: $association->created_at?->toISOString(),
        );
    }

    /**
     * Return an authorized configured thumbnail URL or the safe original.
     */
    private function thumbnailUrl(
        MediaAssociation $association,
        Media $media,
        string $fallback,
    ): string {
        if (! $media->type->supportsConversions()
            || ! $media->relationLoaded('imageVariations')) {
            return $fallback;
        }

        $label = $this->configuredVariations->preferredPreviewVariationLabel();

        if ($label === null || ! $media->hasVariation($label)) {
            return $fallback;
        }

        try {
            $url = $this->attachmentUrls->thumbnail($association);

            return $url !== '' ? $url : $fallback;
        } catch (RuntimeException) {
            return $fallback;
        }
    }

    /**
     * Resolve one localized Media field without leaking non-string values.
     */
    private function translatedString(Media $media, string $field, string $locale): ?string
    {
        $value = $media->translated($field, $locale);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
