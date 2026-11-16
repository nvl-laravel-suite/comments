<?php

declare(strict_types=1);

namespace Nvl\Comments\Integrations;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Relations\StringMorphMany;
use Nvl\Media\Relations\StringMorphToMany;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Slots\MediaSlot;

/** Defines explicit optional Media relationships and the Comments attachment slot policy. */
final class MediaCommentRelations
{
    /**
     * Expose the existing owner media relation without adding a foreign model trait.
     *
     * @return MorphToMany<Media, Comment>
     */
    public static function media(Comment $comment): MorphToMany
    {
        $related = MediaModelRegistry::newInstance();

        return (new StringMorphToMany($related->newQuery(), $comment, 'associable',
            MediaTables::get(MediaTables::Associations), 'associable_id', 'media_id',
            $comment->getKeyName(), $related->getKeyName(), 'media'))
            ->withPivot('collection', 'locale', 'order', 'metadata')->withTimestamps()
            ->orderBy(MediaTables::get(MediaTables::Associations).'.order');
    }

    /**
     * Preserve string owner identifiers across UUID and text database drivers.
     *
     * @return MorphMany<MediaAssociation, Comment>
     */
    public static function associations(Comment $comment): MorphMany
    {
        $related = new MediaAssociation;

        return new StringMorphMany($related->newQuery(), $comment,
            $related->qualifyColumn('associable_type'), $related->qualifyColumn('associable_id'), $comment->getKeyName());
    }

    /** Return Comments' private exclusive attachment policy without a foreign model trait. */
    public static function slot(): MediaSlot
    {
        return (new MediaSlot(self::collection()))->privateExclusive()
            ->onlyKeepLatest(CommentsConfiguration::positiveInteger('nvl-comments.attachments.maximum_per_comment', 5))
            ->maxFileSize(CommentsConfiguration::positiveInteger('nvl-comments.attachments.maximum_file_bytes', 10 * 1024 * 1024))
            ->acceptsMimeTypes(self::mimeTypes());
    }

    /** Return the validated Media collection that holds comment attachments. */
    public static function collection(): string
    {
        $collection = config('nvl-comments.attachments.collection', 'attachments');
        if (! is_string($collection) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,49}\z/', $collection) !== 1) {
            throw new InvalidArgumentException('nvl-comments.attachments.collection must be a Media collection name of 1 to 50 letters, digits, dots, dashes or underscores.');
        }

        return $collection;
    }

    /**
     * Return the validated attachment MIME types; null keeps the image and document defaults.
     *
     * @return list<string>
     */
    public static function mimeTypes(): array
    {
        $configured = config('nvl-comments.attachments.mime_types');
        if ($configured === null) {
            return array_values(MimeType::toStrings([...MimeType::images(), ...MimeType::documents()]));
        }
        if (! is_array($configured) || ! array_is_list($configured) || $configured === []) {
            throw new InvalidArgumentException('nvl-comments.attachments.mime_types must be null or a non-empty list of MIME types.');
        }
        $types = [];
        foreach ($configured as $type) {
            if (! is_string($type) || preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\z/', $type) !== 1) {
                throw new InvalidArgumentException('nvl-comments.attachments.mime_types must contain lowercase type/subtype MIME types.');
            }
            $types[$type] = $type;
        }

        return array_values($types);
    }
}
