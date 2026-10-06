<?php

declare(strict_types=1);

namespace Nvl\Comments\Integrations;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Media\Definitions\Tables\MediaTables;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Relations\StringMorphMany;
use Nvl\Media\Relations\StringMorphToMany;
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
        $related = new Media;

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
        return (new MediaSlot('attachments'))->privateExclusive()
            ->onlyKeepLatest(CommentsConfiguration::positiveInteger('comments.attachments.maximum_per_comment', 5))
            ->maxFileSize(CommentsConfiguration::positiveInteger('comments.attachments.maximum_file_bytes', 10 * 1024 * 1024))
            ->acceptsMimeTypes([...MimeType::images(), ...MimeType::documents()]);
    }
}
