---
name: nvl-comments
description: Install, configure, implement, integrate, test, diagnose, or review nvl/comments in Laravel 13. Use for polymorphic targets, audience-scoped public/member/management reads, anonymous creation, idempotency, threads, revisions, restore/anonymization, reactions, reports, moderation queues, private Media attachments, reconciliation, privacy, authorization, target aliases, TypeScript contracts, or package architecture.
---

# NVL Comments

Treat Comments as a headless domain boundary. Comment text is author-owned
source content with an optional locale; do not create centrally editable
translated variants. The package stores but does not render Markdown.

Resolve canonical target ownership before host scopes or authorization. Global
mention catalogs require a host membership projection; attachments, public
caches, idempotency, locks, retention, and restoration remain tenant-bound.

## Install and configure the consumer

Run the required install and only the publish commands for assets the consumer
will own:

```bash
composer require nvl/laravel-suite:^2.0
php artisan vendor:publish --tag=nvl-comments-config
php artisan vendor:publish --tag=nvl-comments-migrations
php artisan vendor:publish --tag=nvl-comments-skills
php artisan migrate
```

- Use automatic migrations on the effective Comments/Core connection and
  configured logical table names. If publishing the migrations, set
  `nvl-comments.migrations.enabled=false` before migrating so exactly one source
  owns the tables. Use the verified schema upgrade for existing NVL storage.
- Register target resolvers and the final Laravel morph map before the first
  write through `nvl-comments.targets`. When enforcing a morph map, include
  target/actor models and `Nvl\Comments\Models\Comment` for Media ownership.
  Never rename a persisted morph alias or fallback class name.
- Bind consumer `CommentActorResolver`, `CommentQueryScope`,
  `CommentAuthorization`, and `CommentAuthorPresenter` implementations before
  enabling routes through `nvl-comments.actor_resolver.class`,
  `nvl-comments.query_scope.class`, `nvl-comments.authorization.class`, and
  `nvl-comments.author_presenter.class`. Public, member, and management groups under
  `nvl-comments.routes` are disabled by default; add the documented throttling and
  authentication middleware.
- Configure one shared mutation-lock store. Treat Media as optional: `nvl-comments.attachments.enabled=null` selects its
  loaded provider, `true` requires it, and `false` disables the surface. An
  installed package with discovery disabled does not activate attachments.
- Rebuild configuration/routes after changes, generate TypeScript declarations,
  and complete both strict Doctors plus consumer HTTP smoke tests before traffic.

## List-page summaries

- Resolve `ListOwnerCommentSummariesContract` for many-owner count/latest reads;
  do not loop single-owner Actions or query package relationships in consumers.
- Supply a finite list of at most 100 persisted models and explicit actor/audience;
  latest limits are 1–10. Native morph/key maps remain objects for numeric keys,
  retain `order`, and include explicit empty summaries. Direct PHP inputs do not
  require HTTP target aliases or `HasComments`.
- Canonical reloads retain host global/soft-delete scope and package connection.
  Enabled Tenancy requires registered owner resources and exact
  `nvl-comments.tenancy.target_types`. Reject foreign/missing/deleted owners before
  Comment SQL and never switch tenant context during a batch.
- The same SQL predicate feeds visible counts and latest rows; rank after tenant,
  moderation and deletion constraints by created-at/id descending. Three domain
  queries cover 1/25/100 owners of one class, plus a separate cold installation
  probe; extra concrete classes add owner reloads. At most 1,000 latest rows cross
  storage. Latest DTOs expose only id/body/format/timestamps; anonymized body and
  format are omitted. Deleted comments stay excluded even for system actors.
- Bind query-free `CommentBatchQueryScope` when custom imperative policies cannot
  express batches. Derive custom scope only from the same active instance serving
  authorization, single-target SQL scope and batch scope; two stateless configured
  defaults are compatible. Never infer compatibility from a config class or two
  independently stateful custom instances. Unsupported policies fail closed on use.
- Its exact methods are `authorizeOwners(OwnerBatch, CommentActorData,
  CommentAudience)`, `scopeBatchComments(Builder, OwnerBatch, CommentActorData,
  CommentAudience)` and `scopeHostComments(Builder, Model, CommentActorData,
  CommentAudience)`. Add predicates only, without queries or per-owner resolvers;
  host scope also admits the audience. Never let nested ORs reopen package guards.
- Owners with `InteractsWithComments` may use
  `withNvlVisibleCommentCount($actor, $audience, $policy = null)` while retaining
  host selections and global scopes. Use byte-exact correlated native identity,
  including integer/UUID text casts. Host counts select live owners only: owners
  using Laravel's SoftDeletes trait retain their canonical native deletion-column
  null predicate, including custom column names, after removing global scopes or
  including trashed rows. Group existing caller WHERE clauses and only their
  bindings before mandatory live-owner and tenant guards so caller ORs cannot
  escape. Keep unrelated host scopes removable. Resolve fresh readers after
  context changes.

## Start with audience and canonical scope

- Select `CommentAudience::Public`, `CommentAudience::Member`, or
  `CommentAudience::Management` explicitly.
- Resolve targets through registered `CommentTargetResolver` aliases. Never
  accept model classes, tenant IDs, or member scope from request input.
- Treat an HTTP target ID as one percent-encoded path segment. UTF-8, spaces,
  and IDs up to the 255-character domain limit are supported; `/` is not. Map a
  slash-containing external ID to a reversible slash-free application route ID.
- Define the target connection on a fresh registered model. Cross-connection
  Comments read Actions are supported. Package-internal lazy/eager relations
  can cross connections; consumers use `ListCommentsAction` or
  `ListOwnerCommentSummariesAction` with explicit actor/audience instead of
  `comments()` traversal, lazy properties, `load`, or eager loading. Authorized
  C1 host scopes retain their same-connection requirements. Create comments only
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
  each, further limited by a lower `nvl-comments.content.maximum_tags`. Every tag
  must match; status is nullable. Never accept a raw column or JSON path from
  consumer input.
- Consume only the returned `PublicCommentData`, `MemberCommentData`, or
  `CommentManagementData`; `null` means no audience-visible match.
- Keep management authorization in `CommentAuthorization`. The Action reloads
  the target on its declared connection and invokes authorization before comment
  SQL, then applies `CommentQueryScope`, selectors, and deterministic
  `created_at`/`id` descending order. It excludes soft-deleted comments so a
  deleted workflow note falls back to the previous active match.
- Use `DeleteLatestTargetCommentAction` for selector-based deletion. It applies
  List and Delete query scopes before selecting the match, then uses the shared
  deletion writer's mutation locks, ordered row locks, current-revision check,
  counters, and after-commit event.

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
  retaining structural/categorical audit facts. It also clears deletion and
  restoration actor pairs that match the erased author exactly, preserving
  other actors' audit identities and lifecycle timestamps.
- Use `SetCommentReactionAction` with an explicit desired state.
- Use `ReportCommentAction`, `ResolveCommentReportAction`, and
  `ModerateCommentAction` for report/moderation state machines. Preserve
  lifetime and open report-counter semantics.

Keep content, tags, metadata, workflow text, enum, and concurrency validation
inside Actions; transport validation is not sufficient.

## Keep one mutation-lock domain

- Keep `nvl-comments.mutation_lock.enabled` exactly `true` for production.
- Configure `nvl-comments.mutation_lock.store` as one canonical Laravel
  `Illuminate\Contracts\Cache\LockProvider` store shared by HTTP processes,
  workers, schedulers, and reconciliation. `null` selects `cache.default`.
- Use Redis or a shared database cache/lock table for multi-process and
  multi-node deployments.
- Reject `array`, `null`, and `failover` drivers. File locks are single-host
  only; set `nvl-comments.mutation_lock.allow_local_store` to exactly `true` only
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

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared owner identities

- Declare owner class lists in `nvl-core.owners` and reference model classes in `comments` capability configuration. Laravel `getMorphClass()` supplies the host-authored stored identity; declarations do not add global host morph mappings.
- Keep the target resolver and its visibility/identifier checks. The route alias may differ from the shared owner alias. A resolver must return the exact model declared by its owner reference.
- Keep the package allowlist and authorization independent of Core registration. Never authorize a model merely because Core knows it.
- Preserve resolvers, handlers and authorization. Legacy aliases require agreement with native `getMorphClass()` and are removed in major 6; Doctor reports mismatches and stored identity drift without conversion.
- If the host changes its morph map, explicitly reconcile reviewed package-owned columns and affected host relations before cutover. Core and package capability registration never mutate the host morph map or rewrite stored values.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

## Optional attachment adapter

- Keep base Comments operations independent of Media installation and provider
  discovery. Use `CommentAttachmentProjection` and `CommentAttachmentLifecycle`
  for package extension points; neither contract exposes Media persistence types.
- `Comment` has no foreign Media interface or trait. Its optional relations are
  provider-backed; replace Media trait helper calls with the Comments attach and
  detach actions. Preserve slot privacy, size/MIME/count rules, dual authorization,
  lock ordering, and the same-connection requirement.
- Keep Media loaded when anonymizing or reconciling historical attachment state,
  even if new attachments are disabled. Removing discovery does not convert or
  delete persisted associations. Rebuild config/routes after changing discovery
  or the `null`/`true`/`false` integration switch.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-comments.tables.*`, connections through `nvl-comments.connection` with Core/Laravel inheritance. Defaults use `nvl_comments_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=comments --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Cache and lock ownership

Comment mutation locks use `nvl:comments:mutation:` instead of `comments:mutation:`. The selected cache store and tenant partitioning are unchanged; generic host locks are never acquired or removed.

Drain old mutation workers and maintenance processes, then wait for their outstanding lock leases to end before starting the new major across all nodes. Running old and new lock prefixes concurrently would create independent serialization domains. Restart workers after cutover; preserve host-selected stores and keys, and do not flush a shared cache to remove old NVL entries.

## Canonical configuration ownership

- Read/write `nvl-comments` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.

## Compose host workflows through contracts

- Inject focused `Nvl\Comments\Contracts\*Contract` interfaces for the 26 selected public Actions listed in the README; reuse existing equivalent contracts. Preserve the exact native `execute` parameters/defaults and DTO/model/vendor results.
- Use host interface bindings for substitution. Conditional defaults retain prebindings; late replacement reaches newly resolved host services. Keep private package constructor chains concrete and preserve package-owned authorization, queries, validation and mutation semantics.
- Attachment interface and concrete defaults remain conditional while retaining optional Media gates; batched reader and query ownership remain package-owned.


## Consumer runtime and testing contracts

Start with the package README Quickstart and Testing your app sections. Use `nvl:install <package>` for loaded-package common config publication; it does not enable features, run schema or refresh caches. Preserve native host owner keys/morph maps and selected auth/tenancy defaults. Read full runtime defaults and publish advanced config only deliberately.

Inject the supported focused interfaces and preserve host bindings. Returned model handles do not permit package-table queries/writes outside documented capability/extension seams. Host tests may substitute contracts in Laravel's container, use shipped model factories (ordinary make may persist parents; withoutParents()->make is detached), and use Laravel effect fakes deliberately. Only Media/Stripe have dedicated provider/library fakes; do not invent a universal package fake. Settings InteractsWithSettings is definition-only. Host PHPStan may include vendor/nvl/core/support/consumer-audit.neon; no unpublished workbench command is a consumer requirement.

Read docs/events.md and the package README error table. Domain events use schemaVersion=1, model-free facts and actual source-connection commit callbacks; only six declared old Event suffix aliases remain for major 5. Migrate exact listeners/fakes and suffix wildcards, drain old queued payloads, rebuild event cache and restart workers. Delivery is not a durable outbox. The Core exception renderer is opt-in, JSON-only for respondable failures, with exactly message/code/context and host-selected locale. Do not expose diagnostics or reinterpret missing bindings as authorization denial.

Core package logging uses nvl/normal with CSV quiet by default, stable message keys and bounded context; incidents survive quiet. Do not mutate global logger context or log raw row/provider/content/credential payloads. Run only authorized project checks and report new acceptance as pending until actual output exists.
