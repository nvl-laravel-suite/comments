---
name: nvl-comments
description: Install, configure, implement, integrate, test, diagnose, or review nvl/comments in Laravel 13. Use for polymorphic targets, audience-scoped public/member/management reads, anonymous creation, idempotency, threads, revisions, restore/anonymization, reactions, reports, moderation queues, private Media attachments, reconciliation, privacy, authorization, target aliases, TypeScript contracts, or package architecture.
---

# NVL Comments

Treat Comments as a headless domain boundary. Comment text is author-owned
source content with an optional locale; do not create centrally editable
translated variants. The package stores but does not render Markdown.

## Install and configure the consumer

Run the required install and only the publish commands for assets the consumer
will own:

```bash
composer require nvl/laravel-suite:^2.0
php artisan vendor:publish --tag=comments-config
php artisan vendor:publish --tag=comments-migrations
php artisan vendor:publish --tag=comments-skills
php artisan migrate
```

- Keep automatic migrations only for the default connection and canonical
  table names. If publishing the migrations, set
  `comments.migrations.enabled=false` before migrating so exactly one source
  owns the tables; also disable them and ship application migrations for any
  custom layout.
- Register target resolvers and the final Laravel morph map before the first
  write through `comments.targets`. When enforcing a morph map, include
  target/actor models and `Nvl\Comments\Models\Comment` for Media ownership.
  Never rename a persisted morph alias or fallback class name.
- Bind consumer `CommentActorResolver`, `CommentQueryScope`,
  `CommentAuthorization`, and `CommentAuthorPresenter` implementations before
  enabling routes through `comments.actor_resolver.class`,
  `comments.query_scope.class`, `comments.authorization.class`, and
  `comments.author_presenter.class`. Public, member, and management groups under
  `comments.routes` are disabled by default; add the documented throttling and
  authentication middleware.
- Configure one shared mutation-lock store. Configure Media attachments, which
  are enabled by default, completely or set `comments.attachments.enabled` to
  exactly `false` before migration and route caching.
- Rebuild configuration/routes after changes, generate TypeScript declarations,
  and complete both strict Doctors plus consumer HTTP smoke tests before traffic.

## Start with audience and canonical scope

- Select `CommentAudience::Public`, `CommentAudience::Member`, or
  `CommentAudience::Management` explicitly.
- Resolve targets through registered `CommentTargetResolver` aliases. Never
  accept model classes, tenant IDs, or member scope from request input.
- Treat an HTTP target ID as one percent-encoded path segment. UTF-8, spaces,
  and IDs up to the 255-character domain limit are supported; `/` is not. Map a
  slash-containing external ID to a reversible slash-free application route ID.
- Define the target connection on a fresh registered model. Cross-connection
  lazy/eager relationships and Comments read Actions are supported, but
  `has`/`whereHas`/`withCount` require a shared connection. Create comments only
  after a cross-connection target transaction commits, and coordinate target
  deletion with comment retention/anonymization.
- Bind `CommentActorResolver` for HTTP actors and return stable application-owned
  actor types. The default conversion persists an Eloquent morph alias or a
  non-model principal class name, so keep either identity stable after writes.
- Keep actors in one canonical shape: anonymous is null/null/non-system,
  identified actors have bounded non-blank UTF-8 type and ID strings, and the
  system actor is exactly `CommentActorData::system()`.
- Bind the operation-aware `CommentQueryScope` so canonical target, membership,
  tenant, and internal constraints apply before filters, pagination, counts,
  or every ID resolution. Scope implementations only constrain queries; keep
  ability denial in `CommentAuthorization`.
- Bind boolean `CommentAuthorization::allows(...)` for every relevant
  `CommentAbility`. Use `CommentAccessService` for standardized denials.
- Return 404 for inaccessible/cross-target public or member IDs. Management
  permission denials may be 403.
- Preserve byte-exact target, actor, reaction, status, and visibility
  fingerprints. Package models synchronize them; bulk imports must populate
  them with `CommentIdentity` and preserve every fingerprint index.

The default implementation is safe for public reads and actor-owned member
behavior; it denies management. Member and management routes must not be
enabled without consumer-ready actor, scope, policy, and presenter bindings.

## Project the correct contract

- Use `PublicCommentData` only for viewer-independent approved/public reads.
- Use `MemberCommentData` for status, visibility, `isAuthor`, abilities, and
  per-reaction `viewerActive`.
- Use `CommentManagementData` and report management DTOs only after management
  authorization. Gate raw identity separately with
  `CommentAbility::ViewIdentity`; `CommentAbility::Moderate` alone is never
  sufficient.
- Present authors in batches through `CommentAuthorPresenter`; return only safe
  display fields and an audience-scoped opaque key.
- Keep author presentation, visible reply/attachment counts, reactions,
  management identity checks, and abilities free of per-row queries. Policy
  callbacks used during projection must be query-free or request-batched.
- Serialize deleted/anonymized public/member comments as structural tombstones:
  ID, root/parent/depth, revision, visible reply count, and timestamps only.
- Never expose reporter facts, actor IDs, visibility, abilities, revision
  metadata, or Media storage internals through public output.

Public GET responses, including public attachment lists, remain shared-cache
compatible and viewer-independent. Member, management, mutation, revision,
and signed-asset delivery responses are private/no-store.

## Find one latest target comment

- Use `Nvl\Comments\Actions\FindLatestTargetCommentAction::execute(
  Illuminate\Database\Eloquent\Model $target,
  Nvl\Comments\Data\CommentActorData $actor,
  Nvl\Comments\Data\Queries\CommentSelectorData $selector,
  Nvl\Comments\Enums\CommentAudience $audience =
  Nvl\Comments\Enums\CommentAudience::Member)` instead of querying a target's
  `comments()` relation.
- Construct `CommentSelectorData` as `new CommentSelectorData(
  tags: ['candidacy-workflow'], status: CommentStatus::Approved)`. `tags` is a
  distinct list of at most 20 non-blank UTF-8 strings of at most 64 characters
  each, further limited by a lower `comments.content.maximum_tags`. Every tag
  must match; status is nullable. Never accept a raw column or JSON path from
  consumer input.
- Consume only the returned `PublicCommentData`, `MemberCommentData`, or
  `CommentManagementData`; `null` means no audience-visible match.
- Keep management authorization in `CommentAuthorization`. The Action invokes
  it before SQL, then applies `CommentQueryScope`, selectors, and deterministic
  `created_at`/`id` descending order. It excludes soft-deleted comments so a
  deleted workflow note falls back to the previous active match.

For a privileged workflow read, use these exact imports and call:

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
        status: CommentStatus::Approved,
    ),
    audience: CommentAudience::Management,
);
```

## Mutate through Actions

- Create roots/replies with `CreateCommentAction`. Replies inherit the
  canonical parent's target, root, and visibility.
- Pass UUID creation idempotency through `CreateCommentData` or the
  `Idempotency-Key` header. Exact retries return the original active/tombstone
  row; canonical conflicts return 409. Compare the digest before mutable
  content policy, then recheck current target `CommentAbility::Create` or
  scoped parent `CommentAbility::View`/`CommentAbility::Reply` access before
  returning the replay.
- Require exact `expectedRevision` for update, delete, restore, anonymize,
  revision restore, comment moderation, and report review.
- Update through `UpdateCommentAction`; it snapshots the previous content.
- Delete through `DeleteCommentAction`; it soft-deletes and adjusts the direct
  parent count once.
- Restore through `RestoreCommentAction`; default restored state is `pending`,
  and active/anonymized rows or deleted/anonymized parents are rejected.
- Restore history through `RestoreCommentRevisionAction`; snapshot current
  content before applying the selected snapshot.
- Treat `AnonymizeCommentAction` as terminal and irreversible. It scrubs
  package-owned identity/content/revisions and detaches attachments while
  retaining structural/categorical audit facts.
- Use `SetCommentReactionAction` with an explicit desired state.
- Use `ReportCommentAction`, `ResolveCommentReportAction`, and
  `ModerateCommentAction` for report/moderation state machines. Preserve
  lifetime and open report-counter semantics.

Keep content, tags, metadata, workflow text, enum, and concurrency validation
inside Actions; transport validation is not sufficient.

## Keep one mutation-lock domain

- Keep `comments.mutation_lock.enabled` exactly `true` for production.
- Configure `comments.mutation_lock.store` as one canonical Laravel
  `Illuminate\Contracts\Cache\LockProvider` store shared by HTTP processes,
  workers, schedulers, and reconciliation. `null` selects `cache.default`.
- Use Redis or a shared database cache/lock table for multi-process and
  multi-node deployments.
- Reject `array`, `null`, and `failover` drivers. File locks are single-host
  only; set `comments.mutation_lock.allow_local_store` to exactly `true` only
  when every process shares that host filesystem.
- Keep `seconds` and `wait_seconds` as positive integers. Treat any Doctor
  `mutation_lock.configuration_ready` or `mutation_lock.ready` failure as a
  deployment blocker.

## Protect Media

- Attach only through `AttachCommentMediaAction`; it requires both Comments and
  Media authorization for canonical records.
- Bind `Nvl\Media\Contracts\MediaAuthorization` for the canonical `Comment`
  owner context. The default Media policy permits public visibility or exact
  uploader ownership; it will hide private attachments from anonymous public
  readers and unrelated management actors. Grant only the required Associate,
  View, Download, and Mutate abilities, not unrelated private-Media access.
- Keep the attachment slot private/exclusive and enforce configured MIME, size,
  count, and public-Media rules. Keep `maximum_file_bytes` positive; it defaults
  to 10 MiB.
- Comments, Media, and associations must use the same connection for atomic
  writes.
- Preserve lock order: comment mutation lock, sorted Media locks, then database
  row locks.
- Detach only the exact comment association through
  `DetachCommentMediaAction`. Repeated detach is an idempotent success and must
  not delete the Media record.
- Expose only `CommentAttachmentData`, never disk, path, checksum, uploader,
  conversion internals, or raw Media ID.
- Deliver assets through the short-lived association-scoped Comments signed
  routes. Treat the URL as a bearer capability authorized in the canonical
  Comment owner context, and require delivery-route readiness before an HTTP
  attachment mutation writes. Never place the Media UUID, uploader, storage
  path, or variation label in attachment URLs.
- Keep public attachment metadata on the deterministic Media fallback locale
  and cap shared-cache lifetime below the embedded signed-URL lifetime.
- Disabling attachments omits their HTTP routes and lets history-free reads,
  reconciliation, and anonymization avoid Media. Historical associations still
  require the complete same-connection Media schema until explicit cleanup;
  require `attachments.disabled_state_ready` to pass in strict Doctor.
- Run `php artisan nvl:media:doctor --production --strict --format=json` whenever
  attachments are enabled. Comments Doctor does not replace Media production
  scanner, storage, queue, lock, delivery, or authorization checks.

## Moderate and reconcile

- Actionable comments are configured review statuses or rows with open reports,
  including soft-deleted evidence.
- Keep moderation and report queues target-scoped. Use only the model's
  management filter/sort allowlists.
- Ordinary thread lists default to pin-first/newest-first. An explicit
  allowlisted caller sort replaces that priority; the UUID tie-breaker remains.
- Keep `report_count` as lifetime distinct reporters and
  `open_report_count` as currently open reports.
- Run `nvl:comments:reconcile` in dry-run mode first. Require `--repair` for
  mutation and `--force` for production. Never delete data to repair unsafe
  hierarchy.
- Treat `identityFingerprintMismatches` as unrepairable security drift. Correct
  the source import explicitly; reconciliation blocks counter repair for the
  affected comment and never guesses the intended identity.
- Reconciliation uses mutation locks, is interruption-safe, and emits no user
  events.

## Events and verification

Use versioned after-commit events. `CommentChanged` carries
`CommentChangeOperation`; exact retries, no-ops, denials, rollbacks, and
reconciliation must not replay events.

Run:

```bash
php artisan nvl:comments:doctor --strict --format=json
php artisan nvl:comments:reconcile --strict
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

Treat configured consumer-contract checks as structural. They prove bindings
resolve and management contracts are not package defaults; they cannot prove
tenant, membership, role, author-presentation, or private-Media semantics. Run
application HTTP smoke tests for each enabled audience and representative
allowed/concealed actor, including cached configuration and routes.

Also run the Comments Pest package/integration suites, constant-query
regressions, SQLite, MySQL, MariaDB, and PostgreSQL coverage, Pint, PHPStan at
maximum level, TypeScript/contract checks, package-family validation, Composer
validation, dependency audit, and the clean source and relocated-artifact
consumer proofs on Laravel 13.

Use the bundled migrations only on the default connection with canonical table
names. Custom connections or names require disabled bundled migrations and an
application-owned schema preserving the same columns, critical types, explicit
lengths, nullability, defaults, constraints, and indexes. Treat any strict
Doctor `column_definition.*` failure as a deployment blocker.
