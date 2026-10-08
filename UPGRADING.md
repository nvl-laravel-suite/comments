# Upgrading NVL Comments

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. The PHP 8.4/Laravel 13 local Dagger release gate and fresh public Composer installation passed for 5.0.0. Additional compatibility legs need separate evidence; hosts must verify their own adoption.


## Many-owner list reads

Replace per-owner latest/count loops with the container-bound
`ListOwnerCommentSummariesContract`. Supply at most 100 persisted models and an
explicit actor/audience; latest limits are 1–10. Results are native morph/key JSON
objects with first-request `order`, explicit empty owner summaries and safe latest
scalar DTOs. Anonymized body/format are omitted and deleted comments are excluded
for every audience, including system management reads. Existing list tombstones
remain unchanged.

Custom authorization/query-scope bindings require one shared compatible instance
or an explicit query-free `CommentBatchQueryScope` adapter. Package default scope
selection uses the current container bindings, not just configured class names.
Unsupported policies fail closed when used. The optional
`withNvlVisibleCommentCount` host scope adds an authorized count without replacing
selected host columns or scopes. Host counts include live owners only. Owners
using Laravel's SoftDeletes trait retain a mandatory null predicate on their
canonical native deletion column, including custom names, after `withTrashed()`
or `withoutGlobalScopes()`. Caller WHERE clauses and bindings are grouped before
mandatory live-owner and tenant predicates so existing OR conditions cannot
reopen those guards; unrelated host scopes remain removable. Register exact target
resources and `nvl-comments.tenancy.target_types` when enabling Tenancy. See the
README for the adapter example, measured three-query domain budget, cold installation probe and
1,000-row latest transfer ceiling.

## Tenant adoption

Register every target type and its ownership before adoption. Map canonical
comment roots through their targets; replies, revisions, reactions, reports,
mentions, and metadata derive ownership and cannot be assigned independently.

## To 1.0

There is no supported pre-1.0 compatibility contract. Back up development data
and install the clean v1 schema, or import through an application-owned bridge.
The original create migrations were intentionally reshaped for the unreleased
release.

Before cutover:

1. Disable `nvl-comments.migrations.enabled`.
2. Register the final Laravel morph map and `CommentActorResolver` before any
   import or write. Preserve existing target/actor aliases indefinitely; a
   fallback class-name change is also an identity change.
3. Normalize all polymorphic target and actor identifiers to bounded UTF-8
   strings and populate every identity/classification fingerprint with
   `CommentIdentity`; do not derive uniqueness from database text collation.
4. Rebuild canonical root identifiers and depths without cycles.
5. Map status, visibility, deletion, restoration, and anonymization explicitly.
6. Preserve lawful revision and moderation history.
7. Recompute direct-reply, reaction, lifetime-report, and open-report counters.
8. Run `php artisan nvl:comments:reconcile --strict --format=json`.
9. Run `php artisan nvl:comments:doctor --strict --format=json`.

Do not continue while reconciliation reports
`identityFingerprintMismatches`. Those rows are intentionally not auto-repaired;
fix the import's raw identity and derived fingerprint together, then rerun the
read-only audit.

Merge the complete `nvl-comments.mutation_lock` group and rebuild cached
configuration before serving mutations. Production requires `enabled=true`,
positive integer lease/wait values, and one Redis or shared database
`LockProvider` store used by every process. The new `allow_local_store` key
defaults to `false`; set it to `true` only for an intentional single-host file
store. Array, null, and failover stores are rejected.

### Contract changes

- Replace `CommentData` with `PublicCommentData` or `MemberCommentData`.
  Management consumers use `CommentManagementData`.
- `CommentAuthorization` now exposes boolean `allows(...)` and receives a
  `CommentAudience`. Bind the operation-aware `CommentQueryScope` separately so
  trusted target, membership, and tenant constraints run before filtering,
  pagination, counts, and every ID resolution.
- Bind `CommentActorResolver` for HTTP actors and a batched
  `CommentAuthorPresenter` for audience-safe author display. Do not expose
  stored `actor_type` or `actor_id` from public/member serializers. The default
  authenticated-actor conversion persists an Eloquent morph alias or the
  principal class name, so either keep it stable or return an explicit stable
  application type from the resolver.
- Handle the expanded abilities: list, view, identity view, create, reply,
  update, delete, restore, anonymize, react, report, attach, detach, history,
  revision restore, and moderation. `ViewIdentity` is deliberately independent
  from `Moderate`; policies must grant it explicitly before management DTOs
  expose stored actor, reporter, reviewer, or lifecycle identity.
- Pass `CommentAudience::Public`, `Member`, or `Management` to Actions when the
  default public audience is not correct.
- Content updates, deletion, restoration, anonymization, revision restoration,
  comment moderation, and report review require `expectedRevision`.
- Creation accepts a UUID `idempotencyKey`; HTTP callers may instead send the
  matching `Idempotency-Key` header.
- Change listeners must use `CommentChangeOperation` rather than comparing
  free-form operation strings. Events are versioned and after-commit.

### Route changes

Public, member, and management groups are independent and disabled by default.
The member group defaults to `nvl/api/v1/comments/member` with
`api`, `auth`, and throttling middleware. It does not depend on public routes
being enabled. Management routes now also default to `api`, `auth`, and
throttling; strict Doctor rejects enabled public/member/management groups that
lack their required rate-limit or authentication middleware.

Public/member APIs now include safe attachment list and idempotent detach.
Member and management APIs expose separately paginated revision history and
revision restore. Member/management restore deleted comments; management alone
anonymizes. Management discovery includes target-scoped actionable comment and
report queues.

Target identifiers now use Laravel's default single-segment route contract
instead of an ASCII/191-character route regex. Percent-encoded UTF-8 and spaces
are accepted, and resolved target keys remain bounded to 255 characters. `/`
is still unsupported because the identifier is not always the final route
segment; map slash-containing external IDs to a reversible slash-free route ID
in the consuming application.

Public GETs are shared-cache compatible and viewer-independent. Member,
management, mutation, revision, and signed-asset delivery responses are
private/no-store.

Attachment URLs now use short-lived signed Comments routes containing only the
association ID. Ensure `nvl-comments.routes.attachments` remains enabled whenever
HTTP attachment mutations are enabled; the mutation fails before writing when
signed delivery is unavailable. The signed URL is a bearer capability
authorized in the canonical Comment owner context. Configure
`nvl-comments.attachments.signed_url_lifetime` for the desired capability window.
Public attachment metadata now uses the configured Media fallback locale, and
its cache lifetime is capped below that signed lifetime.

`nvl-comments.attachments.maximum_file_bytes` is now a required positive integer
and defaults to 10 MiB. When `nvl-comments.attachments.enabled` is false, attachment
routes are omitted and ordinary reads avoid Media. Historical associations still
require the complete same-connection Media schema until cleanup; strict Doctor
reports `attachments.disabled_state_ready` when that state is unsafe.

If attachments remain enabled, bind a `MediaAuthorization` that authorizes the
private Comment-owner use case for public/member/management actors and run
`php artisan nvl:media:doctor --production --strict --format=json`. Comments
Doctor verifies its own integration boundary but does not replace Media's
production scanner, storage, lock, queue, delivery, and authorization checks.

### Schema changes

Recreate or bridge the `comments` table with:

- non-null 64-character `commentable_identity_hash`, `status_hash`, and
  `visibility_hash` columns, plus nullable `actor_identity_hash`;
- nullable UUID `idempotency_key`, keyed `idempotency_hash`, and its unique
  constraint;
- `open_report_count` in addition to lifetime `report_count`;
- deletion, restoration, and anonymization timestamps and responsible actor
  fields;
- anonymization reason/audit fields;
- v1 target/status/moderation, thread-order, report, and lifecycle indexes.

Recreate or bridge `comment_reactions` with non-null 64-character
`actor_identity_hash` and `type_hash` columns. Its one-reaction-per-actor/type
constraint now uses those hashes. Recreate or bridge `comment_reports` with
non-null 64-character `reporter_identity_hash` and `status_hash`; reporter
uniqueness and actionable queues use those hashes. Preserve the revision-created,
comment parent/root/actor, reaction type/actor, and report status/comment indexes
validated by strict Doctor.

The bundled create migrations are immutable and accept only the default
connection and canonical table names. A custom `nvl-comments.connection` or
`nvl-comments.tables.*` layout requires `nvl-comments.migrations.enabled=false` and
application-owned migrations that retain the same columns, constraints, and
indexes. Strict Doctor also requires the canonical critical column types,
explicit string lengths, nullability, and defaults; a name-compatible but
semantically different schema is not production-ready.

Keep Comments and Media on the same connection when attachments are enabled.
Target models may still live on another connection. Their connection must be
defined on a fresh registered model; lazy/eager relationships and Comments read
Actions are supported, but `has`/`whereHas`/`withCount` require a shared
connection. Run cross-connection comment creation only after the target commit,
and coordinate target deletion with comment retention/anonymization.

Do not add a Comments-specific tenant column or accept tenant/member scope from
request input. Derive scope from the canonical target and consumer contracts.

## Shared owner registry compatibility

Declare owner classes in `nvl-core.owners`, for example `'owners' => [Article::class]`, and reference the same model class from each package capability. Laravel's `getMorphClass()` is the stored owner identity: it returns the host-authored morph alias or the FQCN when no map exists. Core declarations and package allowlists do not add or enforce a host morph map and do not grant authorization.

Legacy alias references remain read compatibility during major 5 and are removed in major 6. A legacy configured alias must agree with the model's current `getMorphClass()`; mismatches are diagnostics and require a host decision. Doctor can inspect declared package owner columns for stored-versus-current identities without rewriting them. If the host introduces or changes its morph map, review and convert only the affected stored columns and reconcile host relationships before cutover. No automatic owner-data conversion or `nvl:owners:upgrade` is provided. Rebuild configuration caches and restart workers after the coordinated change.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Optional Media in the coordinated major

Media moves from a required dependency to a suggested integration. Install it
explicitly if the host uses comment attachments. The new default
`nvl-comments.attachments.enabled=null` selects a loaded Media provider; existing
published `true` values continue to require it, while `false` disables the
attachment surface. Rebuild config and route caches after changing provider
discovery or this switch, then run `nvl:comments:doctor --strict --format=json`.

Replace calls to Media trait helpers on `Comment` with the Comments attach and
detach actions. The model retains adapter-backed attachment relations when
Media is loaded and has no unconditional foreign traits or interfaces. Keep
Media loaded during historical attachment reconciliation and anonymization,
including when new attachments are disabled. Removing the provider does not
rewrite or delete stored attachment rows.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=comments --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=comments --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.

## Major 5 cache and lock identities

Comment mutation locks use `nvl:comments:mutation:` instead of `comments:mutation:`. The selected cache store and tenant partitioning are unchanged; generic host locks are never acquired or removed.

Drain old mutation workers and maintenance processes, then wait for their outstanding lock leases to end before starting the new major across all nodes. Running old and new lock prefixes concurrently would create independent serialization domains. Restart workers after cutover; preserve host-selected stores and keys, and do not flush a shared cache to remove old NVL entries.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

`InteractsWithComments::comments()` are internal storage or lifecycle seams. Migrate direct traversal, eager/lazy loading, and aggregate queries to the package authorized read Actions or explicit C1 host scopes/adapters. Native host queries and opted-in Translatable behavior remain supported.

`CommentManagementData::fromModel`, `MemberCommentData::fromModel`, `PublicCommentData::fromModel`, and `LatestCommentSummaryData::fromModel` are internal package projectors, even for already-loaded rows. Use audience-aware `ListCommentsAction`/`GetCommentAction` for comment display or `ListOwnerCommentSummariesAction`/`ListOwnerCommentSummariesContract` for owner summaries with the explicit actor and audience. These workflows own redaction, body/anonymization handling, and policy.

## Major 5 workflow injection

Replace host constructor dependencies on selected concrete Actions with their focused `Nvl\Comments\Contracts\*Contract` equivalents listed in the README. Existing equivalent workflow contracts are reused. Native concrete constructors, qualifiers, argument defaults, result types, and execution behavior remain compatible. Internal package Action/service chains retain their existing concrete dependencies.

Default workflow registrations use `bindIf`, retaining host interfaces/instances registered before discovery. Register substitutes at the interface key; newly resolved host services receive late replacements. Substituting a workflow does not exercise the native authorization, storage, or lifecycle invariants, which require the owning integration coverage.
