# NVL Comments — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^2.0` |
| Module identifier | `nvl/comments` |
| PHP namespace | `Nvl\Comments` |
| Service provider | `Nvl\Comments\Providers\CommentsServiceProvider` |
| Configuration | `config/comments.php` |

`nvl/comments` is a production-oriented, headless comments domain for Laravel
12–13. It provides polymorphic discussion threads, anonymous and authenticated
creation, audience-aware reads, optimistic lifecycle mutations, reactions,
reports, moderation, revision history, and ownership-authorized Media
attachments. It supports PHP 8.4+ and integer, UUID, ULID, or string target
and actor identifiers.

## Purpose

Use this package when comments must remain a reusable domain capability rather
than becoming controller-owned application logic. It centralizes safe audience
projections, canonical target scoping, threaded lifecycle invariants, moderation
workflows, and attachment ownership while leaving identity, tenancy, and UI
policy in the consuming application.

## Boundaries

The package owns comment-domain integrity but does not assume a user model,
tenant column, frontend, notification system, Markdown renderer, or moderation
UI.

- Comment text is author-owned source content with an optional source locale.
- Plain text and Markdown are stored, not rendered. Consumers must render
  Markdown with HTML disabled or a strict allowlist.
- Membership and tenancy come from the canonical target plus consumer policy.
  Never accept a tenant or member scope from request input.
- Public author data is presented through an audience-safe contract. Stored
  polymorphic actor identities are not public profile data.
- Notification delivery, subscriptions, search, realtime delivery, spam
  scoring, retention schedules, and UI components belong in consumers and can
  subscribe to the package's typed events. Comments owns bounded rich-document
  persistence and normalized current mention references; applications own the
  registered resource resolvers and their authorization policy.

`nvl/comments` declares `nvl/data`, `nvl/filterable`, and `nvl/media`; attachment
support is a first-class integration.

## Install

```bash
composer require nvl/laravel-suite:^2.0
php artisan migrate
```

Laravel auto-discovers
`Nvl\Comments\Providers\CommentsServiceProvider`. Publish only assets the
consumer needs to own:

```bash
php artisan vendor:publish --tag=comments-config
php artisan vendor:publish --tag=comments-migrations
php artisan vendor:publish --tag=comments-skills
```

Choose exactly one migration owner:

1. **Automatic vendor loading (default):** leave `comments.migrations.enabled=true`, do not publish `comments-migrations`, and run `php artisan migrate`.
2. **Host-owned published migrations:** publish `comments-migrations`, set `comments.migrations.enabled=false` before migrating, and maintain the published files as application migrations.

Never run both sources. Laravel retimestamps files published through the migration tag. `php artisan nvl:comments:doctor` reports a warning when automatic loading remains enabled and `database/migrations` contains a timestamp-independent name matching a package migration; `--strict` promotes that warning to failure. The bundled create migrations deliberately target the default database connection and canonical table names. If the application configures a different Comments connection or table name, disable the bundled migrations and ship application-owned migrations for that frozen storage layout.

## Persistence

Package rows use UUID primary keys. Polymorphic target and actor identifiers are
stored as text so common Eloquent key strategies can coexist.

All identity- and classification-sensitive lookups and uniqueness constraints
also use length-delimited SHA-256 fingerprints. This keeps target, actor,
reaction, status, and visibility comparisons byte-exact on databases whose
default text collation is case- or trailing-space-insensitive. Package models
synchronize fingerprints when saved; application-owned bulk imports must
populate them with `CommentIdentity` or persist through the package models.

- `comments` stores canonical target/thread structure, content, status,
  visibility, revision, deterministic counters, idempotency digest, pinning,
  moderation, deletion, restoration, anonymization, and timestamps.
- `comment_revisions` stores immutable pre-mutation content snapshots.
- `comment_reactions` stores one configured reaction type per comment/actor.
- `comment_reports` stores one reviewable report per comment/reporter.
- `comment_metadata_values` stores only keyed hashes for registered queryable
  scalar metadata; the JSON document remains the revisioned source of truth.
- `comment_mentions` stores current ordered mention references by registered
  alias and opaque application ID. It intentionally has no foreign key to an
  application resource; revisions retain immutable server-label snapshots.

`report_count` is a lifetime distinct-reporter count.
`open_report_count` is the current actionable count. Composite indexes support
target/status queues, pinned thread order, report review, and lifecycle audits.
Soft deletion preserves thread structure; physical parent deletion cascades.

## Register a target

Application models may expose the relationship:

```php
use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Contracts\HasComments;
use Nvl\Comments\Traits\InteractsWithComments;

final class Article extends Model implements HasComments
{
    use InteractsWithComments;
}
```

HTTP target discovery uses allowlisted aliases, never arbitrary class names.
Implement `CommentTargetResolver` and register it:

```php
'targets' => [
    'article' => ArticleCommentTargetResolver::class,
],
```

The HTTP alias is only a route-facing allowlist key. Persisted target identity
uses the model's `getMorphClass()` and key. Register stable Laravel morph aliases
before the first comment is written; when morph maps are enforced, include every
polymorphic model used by the integration, including the package `Comment` owner
used by Media attachments:

```php
use App\Models\Article;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Comments\Models\Comment;

Relation::enforceMorphMap([
    'article' => Article::class,
    'comment' => Comment::class,
    'member' => User::class,
]);
```

Morph aliases and HTTP target aliases are independent even when they share a
name. Treat persisted target and actor types as immutable. Changing an existing
alias or a fallback class name after data exists breaks canonical lookup and its
fingerprints/idempotency references; retaining the original alias is safer than
a coordinated application-owned data migration.

Resolvers must return the canonical, policy-scoped target. Actions re-fetch
targets and comments by persisted identity, so dirty caller models cannot widen
access or replace stored ownership.

The registered target model must define its connection on a fresh model
instance; a caller-only `setConnection(...)` override is not a registry
contract. Targets may live on another connection: lazy/eager `comments`
relationships and Comments read Actions remain available, while SQL existence
queries such as `whereHas('comments')`, `has`, and `withCount` require the target
and Comments to share one connection and fail explicitly otherwise.
Cross-connection comment creation must run after the target transaction commits
because no atomic transaction spans both stores. The consuming application must
also coordinate target deletion with comment retention/anonymization; strict
reconciliation diagnoses orphaned target identities but never deletes evidence.

## Create comments and replies

Use Actions as the domain boundary:

```php
$actor = new CommentActorData(type: 'member', id: '42');

$comment = $createComment->execute(
    $article,
    new CreateCommentData(
        body: 'A useful observation.',
        format: CommentFormat::Plain,
        locale: 'en',
        idempotencyKey: '2d82413a-5ec8-4e62-a063-73248b05c480',
    ),
    $actor,
    CommentAudience::Member,
);

$reply = $createComment->execute(
    $article,
    new CreateCommentData(
        body: 'A bounded reply.',
        parentId: $comment->id,
    ),
    $actor,
    CommentAudience::Member,
);
```

Actor identities are exact, case-sensitive application identifiers. Anonymous
actors are represented only by `CommentActorData::anonymous()`, identified
actors require non-blank UTF-8 type and ID values, and trusted system work uses
only `CommentActorData::system()`. Types are limited to 100 characters and IDs
to 255 characters. `fromAuthenticatable()` rejects principals whose
authentication identifier is not an integer or string. For an Eloquent
principal it stores `getMorphClass()`; for another principal it stores the class
name. Prefer an explicit `CommentActorResolver` with stable application-owned
types, or keep the actor morph alias and principal class stable for the lifetime
of persisted comments.

The generated TypeScript mutation contracts preserve backend omission
semantics. Create format, visibility, locale, parent, tags, metadata, and
idempotency key are optional; moderation reason/pinned state and report details
are optional as well. Omitted HTTP fields receive the same constructor defaults
as direct Action callers.

Replies always inherit the canonical target, root, and visibility of their
parent. Callers cannot widen or replace those values. Creation enforces the
configured depth, content, tag, metadata, actor, target, and visibility rules,
increments the direct-parent count once, and dispatches after commit.

Anonymous creation remains available when explicitly enabled and authorized.
The public HTTP group accepts public visibility only.

### Rich documents and mentions

Rich mutations use `CreateRichCommentAction` and `UpdateRichCommentAction`.
They do not change the released plain/Markdown Action or DTO signatures. The
only accepted version-one nodes are paragraph blocks containing text,
hard-break, or mention nodes. Clients submit mention token UUIDs, registered
resource aliases, and opaque IDs only; labels are resolved and stored by the
server. HTML, URLs, client labels, arbitrary fields, and nested children are
rejected.

Rich support is disabled by default through `comments.mentions.enabled` until
the application registers resources. Declarative Eloquent resources are a
convenience for non-sensitive allowlisted fields; custom resolvers retain
ownership of sensitive domain authorization:

```php
'mentions' => [
    'enabled' => false,
    'maximum_per_comment' => 25,
    'maximum_resource_types_per_comment' => 10,
    'suggestion_limit' => 10,
    'maximum_suggestion_limit' => 20,
    'maximum_query_length' => 160,
    'maximum_batch_size' => 100,
    'resources' => [
        'organization' => [
            'model' => Organization::class,
            'searchable_fields' => ['name', 'registration_number'],
            'exposed_fields' => ['name', 'registration_number'],
            'label_field' => 'name',
            'authorization' => OrganizationMentionAuthorization::class,
            'url_resolver' => OrganizationMentionUrlResolver::class,
            'public' => false,
        ],
        'candidacy' => [
            'resolver' => CandidacyMentionResourceResolver::class,
        ],
    ],
],
```

Aliases, searchable/exposed columns, labels, URLs, and authorization are always
server-owned. Declarative queries select only the model key, label, and exposed
fields; escape SQL wildcards; apply the configured authorization scope; order
deterministically; and use bounded parameterized queries. Searchable and exposed
lists each accept at most 25 unique ASCII column names of at most 64 bytes.
Laravel's default `guarded = ['*']` sentinel remains compatible with this
explicit mention allowlist, while explicitly guarded and hidden columns remain
forbidden. Custom resolvers
implement `CommentMentionResourceResolver`. A custom public resolver must also
implement `ViewerIndependentCommentMentionResource`; otherwise public shared
projections use only immutable label snapshots and never call it.

Call `SuggestCommentMentionResourcesAction` for an authorized editor and
`ResolveCommentMentionsAction` for one authorized current comment. Suggestions
return opaque IDs, labels, allowlisted scalar fields, and package-produced URLs.
Every result is capped at 25 fields, 64 bytes per field key, 2,048 bytes per
string field value, and a 2,048-byte relative or HTTP(S) URL. Field strings and
URLs must be valid UTF-8; executable, protocol-relative, control-character, and
non-finite values are rejected at the shared package boundary.
Viewer projections use `resolved`, `missing`, or `restricted`; unavailable
resources expose no live ID, fields, or URL. Projection batches de-duplicate IDs
per alias and preserve token order without N+1 queries. The package bounds
encoded bytes, blocks, nodes, resource aliases, mentions, queries, suggestions,
and resolution batches. `body` is always a deterministic plain-text projection
for compatibility and search. Raw stored documents and resource identity hashes
are hidden from model serialization; viewer-safe documents never serialize raw
opaque resource IDs.

Declarative Eloquent resolution never performs an unscoped existence lookup:
an absent resource and a resource outside the authorization scope both project
as the same snapshot-only `missing` state through one scoped query.

`CommentMentionsChanged` is an after-commit, delivery-agnostic fact containing
only comment/target/revision identity and bounded added/removed alias, opaque ID,
and token facts. Applications own notification recipients, copy, channels, and
delivery.

Applications using custom table names must disable vendor migrations and add
nullable JSON `document` columns to both comment and revision tables plus a
compatible configured mentions table. Restoring a revision rebuilds current
mention rows from its historical server-label snapshot. Soft deletion retains
them, physical deletion cascades them, and anonymization removes all current
references and rich document state.

### Idempotent creation

HTTP callers may send an `Idempotency-Key` UUID; direct callers use
`CreateCommentData::idempotencyKey`.

- The same key and canonical payload returns the original active comment or
  tombstone without replaying counters or events.
- Reusing a key with a different target, actor, parent, visibility, or mutation
  payload returns `409 comment_idempotency_conflict`.
- Lookup includes soft-deleted and anonymized rows. A retry never recreates or
  restores them.
- Digest equality/conflict is checked before mutable content policy, then an
  exact replay rechecks current target `Create` or scoped parent `View`/`Reply`
  access before returning any representation.
- A unique constraint plus reload-after-conflict handles concurrent requests.
- The stored request digest is keyed; raw request payloads are not persisted as
  an idempotency record.

The digest defaults to `app.key`. Set
`COMMENTS_IDEMPOTENCY_DIGEST_KEY` before first use when comment idempotency keys
need an independently managed secret; changing it later invalidates equality
checks for existing keys.

## Audiences, query scoping, and authorization

Three explicit audiences prevent accidental contract mixing:

- `CommentAudience::Public`: approved, public, viewer-independent reads.
- `CommentAudience::Member`: public rows plus the viewer's policy-scoped rows;
  the default adds the viewer's own pending, rejected, and private comments.
- `CommentAudience::Management`: privileged, target-scoped moderation and
  evidence reads.

Bind these consumer contracts before enabling authenticated or management APIs:

- `CommentActorResolver` derives a stable actor from the authenticated request.
- `CommentQueryScope` receives the current `CommentAbility` and constrains the
  canonical target query before filters, sorting, pagination, counts, or
  identifier resolution for that operation.
- `CommentAuthorization::allows(...)` answers capability checks.
- `CommentAuthorPresenter` presents a whole comment batch using display name,
  avatar URL, label, and an audience-scoped opaque key.

The package's safe presenter never exposes stored actor type or ID. The default
authorization/query-scope implementation supports the public contract and
author-owned member behavior, but denies management.

`CommentQueryScope` implementations only add trusted query constraints; they
must not throw authorization denials. Keep denial decisions in
`CommentAuthorization`/`CommentAccessService` so an already-authorized mutation
cannot fail afterward while building scoped aggregates.

`CommentAbility` covers list, view, identity view, create, reply, update,
delete, restore, anonymize, react, report, attach, detach, history, revision
restore, and moderation. `ViewIdentity` is independent from `Moderate`; granting
moderation alone never exposes stored actor, reporter, reviewer, or lifecycle
identity. `CommentAccessService` turns denials into consistent behavior:
inaccessible public/member and cross-target identifiers are 404; management
permission failures may be 403.

## Response contracts

- `PublicCommentData` contains content, thread structure, safe author
  presentation, revision, audience-visible counts, aggregate reactions, and
  timestamps. It contains no status, visibility, actor IDs, report/moderation
  data, abilities, or storage facts.
- `MemberCommentData` adds status, visibility, `isAuthor`, viewer abilities,
  and `viewerActive` for each configured reaction.
- `CommentManagementData` contains moderation/lifecycle state and report
  aggregates. Stored actor identities are included only after a separate
  `CommentAbility::ViewIdentity` authorization check.
- `CommentAttachmentData`, `CommentRevisionData`, author, abilities, and
  reaction-summary DTOs provide narrow nested contracts.

Deleted or anonymized public/member comments become structural tombstones.
Tombstones retain only ID, root/parent/depth, revision, visible reply count, and
timestamps. They omit content, author, reactions, attachments, status,
visibility, and mutation abilities.

All configured reaction types appear in configured order, including zero
counts. Public payloads expose aggregates only; member payloads additionally
expose the current viewer's active state. Reaction actor lists are never part
of these contracts.

Author presentation, reaction summaries, visible reply counts (including
scoped tombstones), visible attachment counts, and management/member
projections are computed in batches. Consumer author presenters and
authorization callbacks used during projection must be query-free or perform
their own request-scoped batching.

## HTTP APIs

Public, member, and management groups are configured and enabled independently:

```php
'routes' => [
    'public' => [
        'enabled' => true,
        'prefix' => 'api/v1/discussions',
        'name' => 'nvl.comments.public.',
        'middleware' => ['api', 'throttle:60,1'],
    ],
    'member' => [
        'enabled' => true,
        'prefix' => 'api/v1/member/discussions',
        'name' => 'nvl.comments.member.',
        'middleware' => ['api', 'auth', 'throttle:60,1'],
    ],
    'management' => [
        'enabled' => true,
        'prefix' => 'api/v1/comments',
        'name' => 'nvl.comments.management.',
        'middleware' => ['api', 'auth', 'throttle:60,1'],
    ],
    'attachments' => [
        'enabled' => true,
        'prefix' => 'api/v1/comment-attachments',
        'name' => 'nvl.comments.attachments.',
        'middleware' => ['api', 'throttle:120,1'],
    ],
],
```

The public, member, and management discussion groups are disabled by default.
Opaque signed attachment delivery is enabled while
`comments.attachments.enabled` is enabled, so independently enabled member or
public APIs can deliver their authorized attachment URLs.

### Public and member routes

Both groups independently provide:

- `GET|POST /targets/{alias}/{id}`
- `GET|PUT|PATCH|DELETE /comments/{comment}`
- `PUT /comments/{comment}/reaction`
- `POST /comments/{comment}/reports`
- `GET|POST /comments/{comment}/attachments`
- `DELETE /comments/{comment}/attachments/{association}`

`{id}` is one URL path segment. Laravel's URL generator percent-encodes UTF-8
and spaces, and the package routes accept Laravel's default segment character
set: every character except `/`. The resolved model key must still be valid,
non-blank UTF-8 with at most 255 characters. An identifier containing `/`
cannot use these routes; expose an application-owned route with a reversible
slash-free external identifier, then resolve the canonical model before calling
the package Actions.

Member routes additionally provide:

- `POST /targets/{alias}/{id}/rich`
- `GET /targets/{alias}/{id}/mentions/{resource}/suggestions?q=...&limit=...`
- `PUT|PATCH /comments/{comment}/rich`
- `POST /comments/{comment}/restore`
- `GET /comments/{comment}/revisions`
- `POST /comments/{comment}/revisions/{revision}/restore`

The attachment list/create/detach entries in these groups, and the equivalent
management entries below, are omitted when `comments.attachments.enabled` is
not exactly `true`.

Public GET responses, including public attachment lists, are evaluated as an
anonymous audience and remain viewer-independent/shared-cache compatible.
Member, management, mutation, revision, signed-asset delivery, and package
error responses are private/no-store. CamelCase is the mutation contract,
including `parentId`, `expectedRevision`, `idempotencyKey`, and `mediaId`.
Package route middleware normalizes `Accept` to `application/json`, so missing
or browser-oriented negotiation cannot turn API authorization and validation
failures into HTML redirects.

Public attachment metadata always uses the configured Media fallback locale,
not request/user locale. Its shared-cache lifetime is automatically capped
below the embedded signed-URL lifetime with a 30-second safety margin, so a
cached list cannot outlive its asset capabilities.

### Management routes

Management discovery is always scoped to a canonical target:

- `GET /targets/{alias}/{id}` returns actionable comments.
- `POST /targets/{alias}/{id}/rich` creates a rich comment.
- `GET /targets/{alias}/{id}/mentions/{resource}/suggestions` returns private,
  authorization-scoped suggestions.
- `PUT|PATCH /{comment}/rich` updates a rich comment.
- `GET /targets/{alias}/{id}/reports` returns actionable reports.
- `PUT /{comment}/moderation`
- `POST /{comment}/restore`
- `POST /{comment}/anonymize`
- `GET /{comment}/attachments`
- `DELETE /{comment}/attachments/{association}`
- `GET /{comment}/revisions`
- `POST /{comment}/revisions/{revision}/restore`
- `GET /{comment}/reports`
- `PUT /reports/{report}`

There is no package endpoint for global cross-target moderation.

## Editing, deletion, restoration, and history

Content update, deletion, restoration, anonymization, revision restoration,
comment moderation, and report review require the exact `expectedRevision`.
Stale mutations raise `StaleCommentException`.

- Updating snapshots the prior content and increments the revision.
- Deleting soft-deletes the comment, records the responsible actor, increments
  the revision, and decrements the active direct-parent count once.
- Restoring locks the trashed row, rejects active/anonymized rows and deleted or
  anonymized parents, restores to `comments.moderation.restored_status`
  (`pending` by default), increments the revision, and increments the parent
  count once.
- Revision history is separately paginated and never embedded in ordinary
  comment payloads.
- Restoring a revision snapshots the current content, applies the selected
  snapshot, and creates a new current revision.

Anonymization is terminal and irreversible. It clears comment actor identity,
content, locale, tags, metadata, identifying moderation text, stored revision
content/identity, and attachment associations. An active comment is soft
deleted and its parent count is adjusted once. Structural target/thread facts
and categorical reports/reactions from other actors remain available for
authorized audit; any report/reaction owned by the erased comment actor is
removed and counters are reconciled. None are exposed through the tombstone.
Later restore and moderation attempts are rejected.

## Reactions, reports, and moderation

`SetCommentReactionAction` takes an explicit desired state, so repeated
activation/removal is a no-op and counters remain stable.

`ReportCommentAction` creates or reopens one report per actor. Lifetime
`report_count` increments only for a new distinct reporter.
`open_report_count` increments on create/reopen and decrements on resolve or
dismiss. Report review advances the owning comment revision. Repeating the
same state with the current revision is event- and counter-neutral.

Actionable moderation comments are those in configured review statuses
(`pending` and `spam` by default) or with open reports. Queues include
soft-deleted evidence, stay target-scoped, and support allowlisted filters for
status, visibility, deletion/anonymization state, open reports, and bounded
dates. Deterministic sorts cover pinning, creation/update time, open/total
reports, and last report time.

The default policy denies management. Consumers must explicitly authorize
moderation and separately authorize exposure of privileged actor identities.

## Attachments

Comments use the private, exclusive Media `attachments` collection. Attaching
requires both Comments authorization and Media authorization for the exact
actor, canonical comment, and canonical Media record. Knowing a Media UUID is
not sufficient.

Media's default `Nvl\Media\Contracts\MediaAuthorization` is based on public
visibility or the exact uploader identity. Comments reject public Media by
default, so anonymous public attachment projections and non-uploader management
actors cannot receive private attachments under that default policy. Bind a
consumer policy that deliberately grants the required `Associate`, `View`,
`Download`, and `Mutate` abilities in the canonical `Comment` owner context;
do not grant blanket access to unrelated private Media.

The package enforces MIME/size/count limits, rejects public Media by default,
and never changes Media visibility. `comments.attachments.maximum_file_bytes`
defaults to 10 MiB and is enforced at the Action boundary as well as by the
Media slot. Comments, Media, and Media associations must share one database
connection for atomic attachment writes.

When `comments.attachments.enabled` is exactly `false`, attachment HTTP routes
are not registered and ordinary comment reads, projections, reconciliation,
and history-free anonymization do not require Media tables. If historical
comment associations still exist, keep the complete same-connection Media
schema available until they are detached or anonymized; strict Doctor treats
incomplete historical attachment state as a deployment failure.

Attachment payloads contain association ID, kind, safe name/metadata, MIME
type, size, authorized asset/thumbnail URLs, remove ability, and creation time.
They never expose disk, path, checksum, uploader, conversion internals, or raw
Media ID. Detach removes only the selected comment association; it never
deletes the Media record or another collection. Missing and already-detached
associations are idempotent successes.

Asset and thumbnail URLs are short-lived, association-scoped signed routes.
Their path/query contains the association ID only—never the Media UUID,
uploader, disk path, or conversion label. Configure their lifetime with
`comments.attachments.signed_url_lifetime`. The URL is a bearer capability
authorized against the canonical Comment owner when issued; delivery still
requires a valid signature and a live attachment association. Keep the
attachment delivery routes enabled whenever an HTTP attachment mutation route
is enabled. Mutation preflight returns
`503 comment_attachment_delivery_unavailable` without writing an association
when signed delivery is not ready.

Attachment and lifecycle operations use one lock order: comment mutation lock,
sorted Media locks, then database row locks.

## Mutation locking

Mutation locking is enabled and required for production readiness. Configure
one canonical Laravel atomic-lock store shared by every HTTP process, queue
worker, scheduler, and reconciliation process:

```php
'mutation_lock' => [
    'enabled' => true,
    'store' => 'redis', // null uses cache.default
    'seconds' => 300,
    'wait_seconds' => 30,
    'allow_local_store' => false,
],
```

`enabled` and `allow_local_store` must be booleans, `store` must be `null` or a
non-blank configured cache-store name, and both timeouts must be positive
integers. Runtime rejects malformed values instead of coercing them.

Use a shared Redis or database cache store in multi-process and multi-node
deployments. The database driver is safe only when every process uses the same
database and the cache lock table is migrated. `array`, `null`, and `failover`
stores cannot preserve one production lock domain and are always rejected.
The `file` driver is single-host only and is rejected unless
`allow_local_store` is exactly `true`; enable that exception only when every
process shares the same host filesystem. Disabling mutation locking makes
strict Doctor unhealthy.

## Events

Events are versioned and dispatch only after commit:

- `CommentChanged` uses `CommentChangeOperation`: `created`, `updated`,
  `deleted`, `restored`, `anonymized`, `moderated`, `report_reviewed`, or
  `revision_restored`.
- `CommentReactionChanged` describes the desired aggregate state.
- `CommentReported` describes a report-domain change for authorized listeners.

Idempotent retries, exact no-ops, denials, rollbacks, and reconciliation do not
replay user events. Queue listeners should still be idempotent.

## Filtering and pagination

`ListCommentsAction` accepts an allowlisted `FilterSet`. Trusted audience and
target scopes are applied before caller filters, sorting, pagination, counts,
and identifier resolution. Raw columns and unsupported operators are rejected
by `nvl/filterable`.

Pagination defaults to 25 and is capped at 100. Root-filtered replies are also
bounded by `comments.threading.maximum_replies_per_page`. Without a caller
sort, threads are pin-first then newest-first with UUID as the deterministic
tie-breaker. An explicit allowlisted caller sort replaces that default pin
priority and still receives the UUID tie-breaker.

## Registered metadata and selectors

Existing JSON metadata remains internal and backward compatible by default.
Applications opt individual scalar fields into validation, equality selectors,
and audience-safe projections by implementing `CommentMetadataSchema` and adding
the class to `comments.metadata.schemas`. A schema owns one stable snake/dot
namespace and a list of `CommentMetadataField` definitions. Fields have a public
snake-case alias, a unique top-level JSON storage key, one of `string`,
`integer`, `boolean`, or `uuid`, explicit nullability/mutability/queryability,
and an explicit `CommentAudience` visibility list. Management sees only fields
that declare `CommentAudience::Management`.

Compatibility mode (`comments.metadata.strict=false`) validates every registered
field while retaining unknown legacy keys internally. Strict mode rejects an
unknown key on mutation and should be enabled only after Doctor reports
`metadata.strict_compatible=true`. Metadata has independent encoded-byte and
registered-field limits; it no longer shares the comment body byte limit.

Queryable values are copied to `comment_metadata_values` as a domain-separated
keyed hash of their type and normalized scalar. The index contains no plaintext
value. Set `COMMENTS_METADATA_DIGEST_KEY`; otherwise Comments falls back to its
idempotency digest key and then `app.key`. Rotating that key invalidates lookup
hashes, so run `nvl:comments:reconcile --repair` to rebuild the index before
depending on metadata selectors.

Use `<namespace>.<field>` aliases in the selector; raw JSON paths, columns,
operators, unregistered fields, and non-queryable fields are rejected:

```php
$selector = new CommentSelectorData(
    tags: ['candidacy-workflow'],
    metadataEquals: [
        'workflow.event' => 'submitted',
        'workflow.sequence' => 4,
    ],
    status: CommentStatus::Approved,
);
```

At most 10 metadata equalities and 20 tags are accepted. String, integer,
boolean, and explicit null equality use the package-owned hash index on every
supported database; callers never query JSON directly. Safe response metadata
is a list of `CommentMetadataProjectionData` records containing a namespace and
an allowlisted scalar `values` record. When no schema value is visible, the
optional property is omitted to preserve existing serialized shapes.

The local package configuration runs this selector contract on SQLite. The
release workflow runs the same `CommentMetadataContractsTest.php` suite on
PostgreSQL 17, MySQL 8.4, and MariaDB 12.3; those drivers are matrix-only unless
the corresponding `DB_CONNECTION` service is configured locally.

Metadata is categorical workflow context, not a secret store or rich reference
system. Do not place credentials, private tokens, personal secrets, model
payloads, or mentions in metadata. Mentions require server-side resolution and
authorization and belong to the package's rich-mention contract.

## Latest target comment read

Use `FindLatestTargetCommentAction` when an application needs one newest
comment for a workflow or summary instead of a paginated thread or a direct
`Comment` query:

```php
use Nvl\Comments\Actions\FindLatestTargetCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Queries\CommentSelectorData;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentStatus;

$comment = app(FindLatestTargetCommentAction::class)->execute(
    target: $candidacy,
    actor: CommentActorData::system(),
    selector: new CommentSelectorData(
        tags: ['candidacy-workflow'],
        metadataEquals: ['workflow.event' => 'submitted'],
        status: CommentStatus::Approved,
    ),
    audience: CommentAudience::Management,
);

$body = $comment?->body;
```

Every selected tag must be present. Tags use the same list, distinctness,
UTF-8, and 64-character limits as comment writes, with a hard query cap of 20
or the lower configured write limit; status is an optional `CommentStatus`.
The Action applies authorization and `CommentQueryScope` before these
selectors, excludes soft-deleted comments, orders by `created_at` then `id`
descending, and returns the DTO for the requested audience or `null`.
Management denial occurs before SQL. Use `DeleteLatestTargetCommentAction` for
the same bounded selector when a workflow needs to delete its newest match
without receiving a `Comment` model. Match resolution, authorization, row lock,
and the current-revision delete lifecycle execute in one package transaction.

## Operations

Audit package readiness:

```bash
php artisan nvl:comments:doctor --strict --format=json
```

When attachments are enabled, also certify the mandatory Media runtime:

```bash
php artisan nvl:media:doctor --production --strict --format=json
```

Doctor verifies schema columns, production-critical types/lengths/nullability/
defaults, indexes, foreign keys, resolvers, contracts, route completeness,
middleware, actor resolution, author presentation, query scoping,
authorization readiness, registered metadata schemas/digest/strict compatibility,
registered mention resource definitions/hard caps/schema, mutation-lock
configuration/topology, and the
Comments/Media connection boundary. It also rejects malformed security switches
and limits, non-canonical bundled-migration storage configuration, missing
fingerprint columns/indexes, and incomplete disabled-attachment history.
Enabled public routes require throttling; enabled member and management routes
require authentication and throttling. Management additionally requires
non-default authorization and query scoping.

Reconciliation validates the current rich document against normalized mention
rows and its plain-text body projection, reports invalid snapshots, identity
collisions, and orphan rows, and can rebuild current rows/body after stored
snapshot revalidation. It never resolves live resources into historical
revisions.

Consumer contract checks are structural: Doctor proves that configured classes
resolve and that management bindings are not the package defaults, but it cannot
prove application-specific tenant, membership, role, author-presentation, or
private-Media decisions. Keep application HTTP smoke tests for every enabled
audience and representative allowed/concealed actor before deployment.

Audit denormalized state without writing:

```bash
php artisan nvl:comments:reconcile --strict
```

Repair explicitly:

```bash
php artisan nvl:comments:reconcile \
    --repair \
    --target=article:42 \
    --chunk=500 \
    --strict \
    --format=json
```

`--repair` additionally requires `--force` in production. Reconciliation audits
and safely repairs reply, reaction, total-report, open-report, root, depth, and
metadata-index drift. It diagnoses cycles, missing targets, invalid attachment associations,
identity/classification fingerprint drift, and unsafe hierarchy damage without
deleting data. Fingerprint mismatches are never auto-repaired, and they block
counter repair for the affected comment so an ambiguous imported identity
cannot be certified or normalized accidentally. Repairs use the mutation lock,
are safe to repeat after interruption, and emit no user events.
Human output is a table; use `--format=json` for automation.

## Adoption and privacy

This is an unreleased v1 contract. Back up development data and re-migrate the
clean schema, or write an application-owned bridge. Do not carry pre-v1
compatibility shims into production.

For imports, disable automatic migrations, normalize polymorphic identifiers to
strings, rebuild root/depth without cycles, map lifecycle states explicitly,
preserve lawful history, recompute counters, run reconciliation, then run
Doctor.

Do not store secrets in comment bodies or metadata. Define retention, legal
erasure, moderator access, and export policies in the consuming application.
Soft deletion preserves a thread and is not privacy erasure; use authorized
comment anonymization for the package-owned record and an application workflow
for actor-wide erasure.

## Development and release checks

From the monorepo root:

```bash
vendor/bin/pint --format agent packages/nvl/comments
vendor/bin/phpstan analyse \
    packages/nvl/comments/src \
    packages/nvl/comments/tests/Fixtures \
    --level=max \
    --memory-limit=2G
vendor/bin/pest \
    --test-directory=packages/nvl/comments/tests \
    --configuration=packages/nvl/comments/phpunit.xml.dist \
    --bootstrap=vendor/autoload.php \
    --compact \
    packages/nvl/comments/tests
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
composer packages:validate
composer contracts:check
composer dependencies:check
composer validate
composer audit --locked --no-interaction
```

The release matrix additionally runs package and integration Pest suites on
SQLite, MySQL 8.4, MariaDB 12.3, and PostgreSQL 17; concurrency coverage;
strict Doctor; TypeScript and public-contract checks; clean source and
relocated-artifact consumers on Laravel 13; and every supported PHP version.

From the suite root:

```bash
composer install
composer quality
```

The development suite uses `ext-pcntl` for Unix process/concurrency coverage.
Production consumers install with `--no-dev` and do not require that extension;
run the complete contributor suite on Linux or macOS.

## License

NVL Comments is released under the MIT License.
