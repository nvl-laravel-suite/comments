# NVL Comments

`nvl/comments` provides threaded comments, revisions, reactions, reports, moderation, mentions, and optional Media attachments for Laravel 13 and PHP 8.4. It is a headless domain package. The host application owns routes, request validation, response resources, identity presentation, and frontend types.

## Install

```sh
composer require nvl/comments:^7.0
php artisan migrate
```

Laravel discovers `Nvl\Comments\Providers\CommentsServiceProvider`. Vendor migrations load by default. If the host publishes them, set `nvl-comments.migrations.enabled` to `false` before migrating so only one migration source runs. The package config and translations may be published with `nvl-comments-config` and `nvl-comments-translations`.

`nvl-comments.targets` maps an alias to a `CommentTargetResolver` for `nvl:comments:reconcile --target=alias:identifier` and the Doctor only; writers and readers do not consult it. Bind `CommentAuthorization`, `CommentQueryScope`, and `CommentBatchQueryScope` to host policy implementations when the default public discussion policy is insufficient. Host policy receives the explicit actor on every call. `SystemActor::Background` identifies background work; it does not grant permission. Human actors must be persisted `Authenticatable` Eloquent models with canonical table, key, and connection. Anonymous creation uses `PublicActor::Anonymous` and requires `nvl-comments.anonymous.enabled=true`.

## Native services

Inject these services from the Laravel container. Inputs are native models, enums, scalars, and shaped arrays; methods require an explicit actor.

| Service | Operations |
| --- | --- |
| `CommentWriter` | `create`, `createRich`, `update`, `updateRich`, `delete`, `restore`, `restoreRevision`, `anonymize` |
| `CommentReader` | `paginate`, `forOwners`, `latest`, `revisions`, `reports` |
| `CommentReactions` | `add`, `remove` |
| `CommentModeration` | `moderate`, `report`, `resolveReport` |
| `CommentHistoryMover` | `moveMany` for authorized owner merges |
| `CommentMentions` | `resolve`, `suggest` |
| `CommentAttachments` | `attach`, `detach`, when `nvl/media` is installed and enabled |

Before running the sample:

- Mutation locks reject the `array`, `null` and `failover` cache drivers, and `file` unless `nvl-comments.mutation_lock.allow_local_store` is `true`. Replies, updates, deletes and reactions therefore throw under Laravel's stock test environment (`CACHE_STORE=array`). Select a shared store with `nvl-comments.locks.mutation.store`; `null` uses `cache.default`.
- New comments are `pending` (`nvl-comments.moderation.new_status`) and the default policy shows `CommentAudience::Public` readers only approved comments, so `paginate` below returns an empty page until the comment is approved or `new_status` is `approved`.
- `createRich` and `updateRich` throw unless `nvl-comments.mentions.enabled` is `true`.

```php
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Services\CommentReader;
use Nvl\Comments\Services\CommentWriter;

$comment = app(CommentWriter::class)->create(
    $article,
    ['body' => 'A comment'],
    $user,
    CommentAudience::Public,
);

$page = app(CommentReader::class)->paginate($article, $user);
$summaries = app(CommentReader::class)->forOwners([$article], $user);
```

Reads return audience-safe shaped arrays or paginators of them. Batch owner reads return `order` and `values`, preserve requested owner order, and return an explicit empty result for an empty owner list. Raw Eloquent models returned by mutation services contain stored actor identity and belong only in trusted application code. Consumers should map their own API resources.

Comment summaries include the boolean `pinned` moderation state. Thread/reply pagination sorts pinned visible comments first, then creation instant and UUID descending. `latest` and owner-batch `latest` retain chronological meaning and include `pinned` for presentation. Pinning never bypasses the selected audience's visibility and authorization predicates.

`paginate`, `latest` and `revisions` include viewer-safe `document` and `mentions` fields. Plain comments return `document: null` and an empty mention list. Rich reads resolve registered resources in bounded batches; restricted resource identifiers are omitted. Historical metadata uses the same audience projection as current comments: a list of namespace/value records, never raw stored metadata. Batch-owner `latest` rows remain compact text snippets.

`replyCount` counts replies visible to the requested audience and actor, including moderation constraints. Page reads use one scoped aggregate rather than one count query per comment. Stored counters remain internal lifecycle totals. Custom `CommentQueryScope` predicates are nested inside the mandatory target boundary, so an `orWhere` cannot widen the read to another target. Use predicate/relationship constraints in these scopes.

Writes preserve canonical target identity, ownership admission, revision checks, idempotency, and transactional lifecycle changes. Rich documents accept only `version` and `blocks` at the document root, with bounded blocks and registered mention resources. Unsupported document, block and node fields are rejected before persistence. Metadata validates registered fields and projects only their audience-visible values; enable `metadata.strict` to reject unregistered storage keys. A missing optional Media provider does not affect comment reads and writes; an explicitly requested attachment operation fails clearly.

Actor and target facts used for authorization reload from their writer connections while retaining host scopes. Visibility queries, including batch summaries and correlated host counts, also use the writer connection so a stale read replica cannot expose newly hidden comments. Batch targets still reload once per model class.

The `withNvlVisibleCommentCount` model scope accepts the same explicit native principals as the services: an authenticated model, `SystemActor::Background`, or `PublicActor::Anonymous`. It resolves the canonical actor internally and retains host scopes and selections.

Reply pages (`paginate` with a `parentId`) are additionally capped by `nvl-comments.threading.maximum_replies_per_page`. `CommentMentions::suggest` uses `nvl-comments.mentions.suggestion_limit` when no limit is passed. Text inputs reject NUL bytes.

Moderation is authorized as `CommentAbility::Moderate`, except a request that only sets the `flagged` status without `pinned`, which is authorized as `CommentAbility::Flag`. Grant `Flag` to members who may flag but not approve, hide or pin. Moderation records the last approval separately from the current pin. A new approval stamps `approved_by_type`, `approved_by` and `approved_at`. Pinning stamps the matching `pinned_*` fields, and unpinning clears those fields. Existing records gain no inferred attribution. Flagged comments are confined to authorized management reads, including when a host query scope would otherwise return them.

`CommentHistoryMover::moveMany($actor, $sourceType, $targetType, $targets)` accepts Core owner declarations on the comment writer connection and a source-to-destination key map. Grant `CommentAbility::MoveHistory` for both the source and destination in the host policy. Each history moves once, including soft-deleted rows, with IDs, relationships, and timestamps preserved. The move joins the caller's database transaction.

`CommentWriter::createEncoded()` and `updateEncoded()` accept a versioned `CommentContentCodec` and the original payload. `HtmlCommentCodec` uses PHP's DOM extension. `RichBlockCommentCodec` accepts JSON or an array of text blocks. Unsupported node types raise a mutation error. Comments store the codec, version and original payload with their revision history, and normal reader projections expose the plain-text body. Applications that expose or render the original payload own its authorization and sanitization.

Use `CommentSchemaAdopter` to stage a quiescent legacy `comments` and `comments_reactions` graph into empty native Comments storage. Grant `CommentAbility::AdoptHistory` globally and for each affected target. Provide `actor_type` for the legacy user identity and optionally `connection`, `actor_types` by former user provider, and `owner_types` by former morph type. Review the output of `plan()`, supply its `fingerprint` to `apply()`, and call `verify()` before switching the host integration. Retry a partial copy with the same fingerprint. The adopter preserves IDs, timestamps (including null legacy timestamps, which readers return as `null`) and moderation facts, reconstructs ancestry and counters, and archives legacy content and metadata in the private source envelope. Reconciliation recognizes that envelope, and the one written by `createEncoded()`, as healthy and keeps it during repair. A keyed retry of a comment whose history `CommentHistoryMover` moved to another target is an idempotency conflict. Resolve unsupported content or identity conflicts before staging. Perform native Ownership adoption separately if the host needs owner partitioning.

Correlated host counts and `whereHas('comments')` / `withCount('comments')` use the `(commentable_type, commentable_id)` index and compare the host key in the comments column's collation, so host key columns with another collation are supported. A `database` cache lock store may share the Comments connection: inside a PostgreSQL transaction each lock attempt runs in a savepoint, so contention is reported as `CommentMutationBusyException` instead of aborting the host transaction.

Attachments live in the Media collection `nvl-comments.attachments.collection` (default `attachments`) and accept `nvl-comments.attachments.mime_types` (default `null`: Media's image and document types). Relations, writes, anonymization cleanup, reconciliation and Doctor all use these values. When adopting legacy comment media, set them to the legacy values before switching the host integration. For GCT, whose comment media use the `comment-attachments` collection and also allow Word documents and archives:

```php
'attachments' => [
    'collection' => 'comment-attachments',
    'mime_types' => [
        'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/svg+xml', 'image/bmp',
        'application/pdf', 'text/csv', 'text/plain', 'application/json', 'application/xml',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip', 'application/gzip',
    ],
],
```

Confirm the list against GCT's upload validation; an association whose media type is not listed is reported by reconciliation.

Force-deleting a comment removes its replies through Eloquent so every removed comment cleans up its Media attachments. The whole deletion runs in one transaction: a veto on the comment or any reply rolls everything back, and Media removes files only after the commit. That cleanup runs as `SystemActor::Background`; the host `MediaAuthorization` must admit it, otherwise the force delete fails.

Idempotency digests and the metadata index are HMACs keyed by `nvl-comments.idempotency.digest_key` and `nvl-comments.metadata.digest_key`, which fall back to `APP_KEY`. Pin `NVL_COMMENTS_IDEMPOTENCY_DIGEST_KEY` to the current `APP_KEY` before rotating `APP_KEY`. After a change of the effective key, earlier idempotency keys are rejected as conflicts and `php artisan nvl:comments:reconcile --repair` rebuilds the metadata index.

Run `php artisan nvl:comments:doctor` after configuring the package. See [events](docs/events.md) for domain notifications and [contributing](CONTRIBUTING.md) for package development.

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.
