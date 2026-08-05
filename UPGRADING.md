# Upgrading NVL Comments

## To 1.0

There is no supported pre-1.0 compatibility contract. Back up development data
and install the clean v1 schema, or import through an application-owned bridge.
The original create migrations were intentionally reshaped for the unreleased
release.

Before cutover:

1. Disable `comments.migrations.enabled`.
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

Merge the complete `comments.mutation_lock` group and rebuild cached
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
The member group defaults to `api/v1/member/discussions` with
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
association ID. Ensure `comments.routes.attachments` remains enabled whenever
HTTP attachment mutations are enabled; the mutation fails before writing when
signed delivery is unavailable. The signed URL is a bearer capability
authorized in the canonical Comment owner context. Configure
`comments.attachments.signed_url_lifetime` for the desired capability window.
Public attachment metadata now uses the configured Media fallback locale, and
its cache lifetime is capped below that signed lifetime.

`comments.attachments.maximum_file_bytes` is now a required positive integer
and defaults to 10 MiB. When `comments.attachments.enabled` is false, attachment
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
connection and canonical table names. A custom `comments.connection` or
`comments.tables.*` layout requires `comments.migrations.enabled=false` and
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
