# Comments host integration and native recipes

Use [installation](installation.md) for setup and [API](api.md) for full declarations.
`App\...` example classes are host-owned implementations.

## Targets and the three visibility seams

A `CommentTargetResolver` declares `alias(): string` and
`resolve(string $identifier): ?Model`. Resolve through constrained host queries.
Register aliases in `nvl-comments.targets`:

```php
config(['nvl-comments.targets.article' => \App\Comments\ArticleTargetResolver::class]);
```

For a shared canonical owner alias the value may be
`['resolver'=>ArticleTargetResolver::class, 'owner'=>'article']`; the owner alias
must already resolve through the native shared owner registry. `HasComments` marks
the target relation contract; `Nvl\Comments\Traits\InteractsWithComments` supplies
its relation and the actor-aware correlated count scope. The returned model
must retain the registered identity. Prefer `CommentTargetRegistry::resolve` for
transport alias/ID mapping, never arbitrary request-supplied class names.

Three separate contracts govern host visibility:

- `CommentAuthorization::allows($ability,$actor,$comment,$target,$audience,$context)`
  makes capability decisions and should use preloaded/cached facts during projections.
- `CommentQueryScope::scopeComments(...)` constrains SQL before list/count/history
  selection; it does not reopen unrelated abilities during an already-admitted read.
- `CommentBatchQueryScope` admits loaded owner batches and scopes batch/correlated SQL.

```php
$this->app->singleton(\App\Comments\HostCommentsPolicy::class);
$this->app->bind(\Nvl\Comments\Contracts\CommentAuthorization::class,
    fn ($app) => $app->make(\App\Comments\HostCommentsPolicy::class));
$this->app->bind(\Nvl\Comments\Contracts\CommentQueryScope::class,
    fn ($app) => $app->make(\App\Comments\HostCommentsPolicy::class));
$this->app->bind(\Nvl\Comments\Contracts\CommentBatchQueryScope::class,
    fn ($app) => $app->make(\App\Comments\HostCommentsPolicy::class));
```

That example policy implements all three contracts; otherwise bind separate actual
implementations. Without an explicit batch binding, automatic derivation works for
the native default or a single shared authorization/scope object implementing the
batch contract. Incompatible custom seams fail closed for batch reads.

The default policy covers conservative public/member discussion, denies system
actors, moderator/identity disclosure and arbitrary management access. Anonymous
creation is disabled by default (`nvl-comments.anonymous.enabled=false`). Enabling it
is not a replacement for target/audience policy. Explicit Eloquent actors reload
canonical storage/facts; null is not a supported substitute for public/system enums.

## Plain, Markdown, replies and retries

```php
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Enums\CommentAudience;

$writer = app(CommentWriter::class);
$comment = $writer->create($article, [
    'body' => 'Useful article.', 'format' => 'plain',
    'locale' => 'en', 'idempotency_key' => $stableRetryKey,
], $reader, CommentAudience::Public);
$comment = $writer->update($comment, [
    'body' => 'A useful, clear article.',
    'expected_revision' => $comment->revision,
], $reader, CommentAudience::Public);
```

Create accepts `body`, `format`, `visibility`, `locale`, `parentId`, `tags`,
`metadata`, `idempotency_key`. Plain/Markdown use `create`; a rich document uses
`createRich` with `document` instead of body/format. Visibility values are `public`,
`private`, `internal`; audience is a separate enum and grants no permission by itself.
A nullable `parentId` creates a root or a reply; native target, depth, parent and
moderation rules still apply. Do not accept author/moderator/owner/status fields.

Rich input is a version-one document with a list of paragraph blocks. Each paragraph
has a `children` list containing only text, hard-break or mention nodes:

```php
$document = ['version' => 1, 'blocks' => [
    ['type' => 'paragraph', 'children' => [
        ['type' => 'text', 'text' => 'Please review this, '],
        ['type' => 'mention', 'tokenId' => (string) \Illuminate\Support\Str::uuid(), 'resource' => 'member', 'id' => 'member-1'],
        ['type' => 'hard_break'],
        ['type' => 'text', 'text' => 'Thank you.'],
    ]],
]];
```

Pass this as `document` to `createRich`; use a registered, authorized mention
resource and its exact string ID. Mention tokens are unique UUIDs. Input must not
contain `labelSnapshot`, labels, URLs or extra node keys: the server resolves and
stores mention labels, and viewer reads apply mention visibility again. Rich writes
require mentions enabled even when a document has only text. Native block, node,
mention, resource and body-length limits apply; arbitrary HTML or editor node types
are not accepted.

Creation defaults to pending moderation. A matching retry key replays the same
creation safely, retaining native digest scope; do not rotate it on network retry
or reuse it with different content. Configure stable deployment digest secrets
where required by `idempotency.digest_key` / metadata indexing; do not use local
or dummy mutation-lock stores to bypass real production locking.

Plain update requires body and integer `expected_revision`; optional fields are
`format`, `locale`, `tags`, `metadata`. Omitted optional values retain their
state; explicit nullable locale clears it, while array fields use their allowed
array shapes rather than arbitrary nulls. Editing may reset moderation status to
the configured edited status. Use `updateRich` for rich changes, with its own
closed document/revision schema. Delete/restore take expected revision separately;
`restoreRevision` identifies both current comment and historical revision.

## Safe reads, metadata and mentions

```php
$page = app(\Nvl\Comments\Services\CommentReader::class)
    ->paginate($article, $viewer, CommentAudience::Public, 25);
$latest = app(\Nvl\Comments\Services\CommentReader::class)
    ->latest($article, $viewer, CommentAudience::Public);
```

Paginate returns a paginator of safe arrays, defaulting to roots; a parent ID
selects that parent's visible replies. Reply counts are viewer/audience aware.
Latest is a safe array or null. History and reports have their own policy and
projection; do not serialize native mutation models, stored actor identifiers,
private metadata indexes or raw rich-document references to public clients.
Owner batches return ordered `order`/`values` projections with bounded target,
visibility, reaction and mention work; latest snippets do not become rich documents.

Register `CommentMetadataSchema` classes in `nvl-comments.metadata.schemas`.
Retain namespace validation, audience projection, maximum byte/field limits and
strictness rather than treating metadata as a public arbitrary attribute bag.

Mentions require `nvl-comments.mentions.enabled=true`. Each resource definition
selects exactly one custom `resolver` or declarative `model`. Custom entries allow
`resolver` and `public`; declarative entries use `model`, `searchable_fields`,
`exposed_fields`, `label_field`, `authorization`, optional `url_resolver`, `public`.
The authorization class implements `CommentMentionResourceAuthorization`, with
an optional `CommentMentionUrlResolver`. Custom resources marked public must also
implement `ViewerIndependentCommentMentionResource`. A public marker does not remove viewer
policy. Keep resolver output bounded and do not expose unlisted attributes.

`CommentMentions::suggest($target,$actor,$resource,$query,$limit,$audience)` returns
safe id/label/fields/url suggestions. `resolve($comment,$actor,$audience)` projects
stored mentions for that viewer; rich reads reuse bounded projection. Do not
persist frontend-resolved labels/URLs as authority. Rich documents use the native
versioned block/node schema shown above; consult [API](api.md) for exact service arguments.

## Moderation, reporting, reactions and privacy

```php
$comment = app(\Nvl\Comments\Services\CommentModeration::class)->moderate($comment, [
    'status' => 'approved', 'expected_revision' => $comment->revision,
    'reason' => 'Reviewed', 'pinned' => false,
], $moderator);
```

Moderation accepts `status`, `expected_revision`, nullable `reason`, nullable
`pinned`. Status values are pending/approved/rejected/hidden/spam.

Reader summaries expose `pinned` as a boolean. Thread/reply pagination places admitted visible pins first, with creation instant/UUID tie-breakers. `latest` and owner-batch latest rows remain chronological and expose the same flag; pinning never grants visibility.
`report` accepts only `reason`, optional nullable `details`, and an identified
Authenticatable actor; it creates/refreshes that actor's report. There is no
caller `idempotency_key` field for reports. `resolveReport` accepts `status`,
`expected_revision`, `resolution`; its revision protects the current comment,
not a fabricated independent report revision. Open is not a resolved status.

`CommentReactions::add/remove` take a configured reaction type and explicit actor;
they express desired state and are retry-safe without a toggle or caller key.
`anonymize` takes `expected_revision` and `reason`, defaults to Management audience,
and preserves native irreversible privacy/retention rules. Do not restore author
identity from history or manufacture a new raw-model serialization after anonymizing.

## Optional attachments, models and lifecycle

`attachments.enabled` is null for loaded Media detection, false to disable,
true to require the provider. Plain comments boot without Media; requesting
attachments while unavailable fails explicitly. Attach/detach uses
`CommentAttachments` and native Media lifecycle, slots and permissions. Assets
must pass Associate/View/Download; public assets require explicit
`allow_public_media`. Comments and Media need the same connection for atomic work.
The host owns signed asset delivery, not invented package attachment routes.

Set `nvl-comments.models.comment` before boot to a concrete native subclass.
Keep UUID, required casts, content/document hooks, effective storage and ownership.
Ownership is either unscoped or inherited through the canonical `commentable`
relation. History, reports and reactions remain fixed package models. Extend
listeners with observers/bootable traits and keep parent native hooks.

Mutations own source-connection transactions, pessimistic/distributed mutation
locks, optimistic revisions and outer-commit events. Audience/query scopes remain
nested inside canonical target and Ownership predicates; loose OR predicates must
not cross targets. `withNvlVisibleCommentCount` and batch/correlated reads also take explicit
canonical actors. Test these boundaries, report uniqueness, rich privacy,
idempotency, revision restoration, Media absence and enclosing rollback. Map safe
conflicts/not-found/denials at the host without exposing other targets or SQL.
