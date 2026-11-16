<?php

declare(strict_types=1);

namespace Nvl\Comments\Integrations;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Comments\Contracts\CommentAttachmentLifecycle;
use Nvl\Comments\Models\Comment;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaLibrary;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Integrations\OptionalIntegration;

/** Retains asset mutation locking and historical cleanup behind an optional Media adapter. */
final readonly class MediaCommentAttachmentLifecycle implements CommentAttachmentLifecycle
{
    /** Retain the public detach contract and asset mutation lock domain. */
    public function __construct(private MediaAttachments $attachments, private MediaMutationLock $locks, private OptionalIntegration $integrations, private MediaLibrary $library) {}

    /**
     * Require Media only when attachments are enabled or historical state needs scrubbing.
     */
    public function stateRequired(Comment $comment): bool
    {
        $attachmentsEnabled = $this->integrations->enabled('comments.attachments.enabled', MediaServiceProvider::class);
        $association = new MediaAssociation;
        $associationSchema = $association->getConnection()->getSchemaBuilder();

        if (! $associationSchema->hasTable($association->getTable())) {
            if (! $attachmentsEnabled) {
                return false;
            }

            throw new InvalidArgumentException(
                'Comment anonymization requires the Media association table when attachments are enabled.',
            );
        }

        $hasHistoricalState = MediaAssociation::query()
            ->where('associable_type', $comment->getMorphClass())
            ->where('associable_id', $comment->id)
            ->where('collection', MediaCommentRelations::collection())
            ->exists();

        if (! $attachmentsEnabled && ! $hasHistoricalState) {
            return false;
        }

        $media = MediaModelRegistry::newInstance();
        $commentConnection = DB::connection($comment->getConnectionName())->getName();
        $associationConnection = DB::connection($association->getConnectionName())->getName();
        $mediaConnection = DB::connection($media->getConnectionName())->getName();
        $mediaTableExists = $media->getConnection()->getSchemaBuilder()
            ->hasTable($media->getTable());

        if ($commentConnection !== $associationConnection
            || $commentConnection !== $mediaConnection
            || ! $mediaTableExists) {
            throw new InvalidArgumentException(
                'Comment anonymization requires Comments and complete Media state on one database connection when attachment state exists.',
            );
        }

        return true;
    }

    /**
     * Return deterministic attachment asset identifiers, optionally holding row locks.
     *
     * @return list<string>
     */
    public function ids(Comment $comment, bool $lock = false): array
    {
        $query = MediaCommentRelations::associations($comment)->where('collection', MediaCommentRelations::collection())->orderBy('media_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return array_values($query->pluck('media_id')->filter(static fn (mixed $id): bool => is_string($id))->unique()->all());
    }

    /**
     * Run anonymization while every selected asset mutation lock is held.
     *
     * @param  list<string>  $ids
     * @param  Closure(): Comment  $callback
     */
    public function synchronize(array $ids, Closure $callback): Comment
    {
        return $this->locks->executeMany($ids, $callback);
    }

    /** Detach one historical attachment through Media's public mutation contract. */
    public function detach(Authenticatable|SystemActor $actor, string $id, Comment $comment): int
    {
        return $this->attachments->detach($actor, $id, $comment, MediaCommentRelations::collection());
    }

    /** Preserve force deletion cleanup while retaining assets shared with other owners. */
    public function deleteOwnerMedia(Comment $comment): void
    {
        if (config('nvl-media.delete_media_on_model_delete', true) !== true) {
            return;
        }
        $association = new MediaAssociation;
        if (! $association->getConnection()->getSchemaBuilder()->hasTable($association->getTable())) {
            return;
        }
        $associations = MediaCommentRelations::associations($comment)->get();
        $ids = [];
        foreach ($associations as $association) {
            $ids[] = $association->media_id;
        }
        $ids = array_values(array_unique($ids));
        $this->locks->executeMany($ids, function () use ($comment, $ids): void {
            foreach ($ids as $id) {
                $shared = MediaAssociation::query()->where('media_id', $id)
                    ->where(static function ($query) use ($comment): void {
                        $query->where('associable_type', '!=', $comment->getMorphClass())
                            ->orWhere('associable_id', '!=', $comment->id);
                    })->exists();
                if ($shared) {
                    $this->attachments->detach(SystemActor::Background, $id, $comment);
                } else {
                    $this->library->delete(SystemActor::Background, $id, owner: $comment);
                }
            }
        });
    }
}
