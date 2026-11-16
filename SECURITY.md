# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/comments/security/advisories/new).

Security fixes are provided for the published `7.x` release line. Earlier lines no longer receive fixes. Composer declares PHP `^8.4` and Laravel `^13.0`. The local Dagger release gate verifies PHP 8.4/Laravel 13 with SQLite and PostgreSQL 17 persistence contracts. PHP 8.5 requires separate compatibility evidence. Upstream security lifecycle limits still apply.

Include the target type, audience, visibility, actor kind, service method,
Media visibility, and impact. Do not include personal production content,
credentials, idempotency secrets, or private asset URLs.

## Deployment requirements

- The package ships no HTTP routes, controllers, requests, resources, or signed
  URLs. The host owns transport: routes, middleware, request validation,
  rate limiting, response resources, and caching. Call the native services
  (`CommentWriter`, `CommentReader`, `CommentReactions`, `CommentModeration`,
  `CommentHistoryMover`, `CommentMentions`, `CommentAttachments`) only after
  the host has authenticated the request, and run strict Doctor before
  serving traffic. Doctor proves structural readiness, not host policy, so
  keep host HTTP tests for every exposed surface.
- Pass an explicit actor to every service call. Human actors must be persisted
  `Authenticatable` Eloquent models with a canonical table, key, and
  connection; they are reloaded from the writer connection before
  authorization. `SystemActor::Background` identifies background work and
  grants nothing by itself. `PublicActor::Anonymous` creates comments only
  when `nvl-comments.anonymous.enabled=true`. No other principal is accepted.
- Derive membership and tenancy from the canonical target and host policy.
  Never accept a tenant or member scope, an actor, or a polymorphic model class
  from request input.
- Register stable morph aliases before the first write. Targets and actors are
  persisted through `getMorphClass()`; treat those persisted types as
  immutable.
- Resolved canonical target IDs are bounded to 255 UTF-8 characters. Resolve
  route parameters to the target model in the host before calling a service.
- Preserve the package's byte-exact identity fingerprints and unique indexes.
  Do not replace them with case-insensitive raw-text identity constraints, and
  populate them explicitly in application-owned bulk-import paths.
- Treat the bundled migrations as an immutable canonical layout on the default
  connection. Custom Comments connections/table names require disabled bundled
  migrations and application-owned schema management.
- Define every target connection on a fresh registered model. Cross-connection
  target creation must commit before comment creation; coordinate target
  deletion with comment retention/anonymization. SQL relationship existence
  queries require a shared connection.
- Bind `CommentAuthorization`, the operation-aware `CommentQueryScope`, and
  `CommentBatchQueryScope`. The scope runs before filters, pagination, counts,
  and every identifier resolution. Map inaccessible and cross-target
  identifiers to 404 in the host to avoid enumeration.
- Keep public output viewer-independent. Never attach authenticated user state
  to public projections or shared-cache responses.
- Raw models returned by mutation services carry stored actor identity. Map
  reader arrays to host resources and never expose stored actor IDs, reporter
  identity/details, moderation facts, visibility, revision internals, Media
  IDs, disk, path, checksum, uploader, or conversion details publicly.
- Treat `CommentAbility::ViewIdentity` as a distinct privileged capability.
  Granting `Moderate` or `Flag` must not implicitly expose actor, reporter,
  reviewer, or lifecycle identities. `Flag` admits only a flag-only status
  change; approval, hiding, rejection, and pinning require `Moderate`.
- Render Markdown and encoded source payloads through a safe allowlist,
  rate-limit every mutation surface, and keep page, body, tag, metadata,
  revision, and attachment limits bounded.
- Keep mutation locking enabled and configure one canonical `LockProvider`
  cache shared by every HTTP process, worker, scheduler, and reconciliation
  process. Prefer Redis or a shared database lock table. `array`, `null`, and
  `failover` are never safe lock domains. File locking is single-host only and
  requires the explicit `nvl-comments.mutation_lock.allow_local_store=true`
  acknowledgement. Strict Doctor must pass after cache or topology changes.

## Attachments

Private Media association requires both Comments authorization and Media
authorization for the canonical actor/comment/Media tuple. A Media UUID is not
an ownership capability. Keep public Media attachment disabled unless public
asset reuse is intentional. Keep `maximum_file_bytes` as a positive integer and
set it to the largest object the application is prepared to authorize and
deliver. Restrict `nvl-comments.attachments.mime_types` to types the host can
deliver safely; `null` keeps the image and document defaults.

The default Media policy permits public visibility or exact uploader ownership;
it does not make a private comment attachment visible to an anonymous public
reader or an unrelated moderator. Bind a `MediaAuthorization` that explicitly
handles the canonical `Comment` owner context, including the
`SystemActor::Background` cleanup that runs when a comment is force-deleted,
and run `php artisan nvl:media:doctor --production --strict --format=json`
whenever attachments are enabled. Comments Doctor is not a substitute for
Media's production readiness audit.

Comments, Media, and Media associations must share a connection for atomic
writes. Detach only the selected association; never delete the underlying
Media record or another collection. Asset delivery is host-owned: authorize the
canonical comment and its owner on every download, and send private/no-store
responses for private attachments and every attachment mutation.

Disabling attachments does not erase historical associations. Keep the
complete same-connection Media schema available until historical comment
associations in the configured `nvl-comments.attachments.collection` are
explicitly cleaned up. Treat a strict Doctor `attachments.disabled_state_ready`
failure as a deployment blocker.

## Lifecycle and privacy

Use optimistic `expectedRevision` checks for all lifecycle, comment moderation,
and report-review mutations. Preserve the unique constraints for creation
idempotency, reactions, reports, and associations. The keyed idempotency digest
protects canonical request comparison. Pin `NVL_COMMENTS_IDEMPOTENCY_DIGEST_KEY`
to the current `APP_KEY` before rotating `APP_KEY`; a changed digest key turns
retries of earlier idempotency keys into conflicts and requires
`nvl:comments:reconcile --repair` for the metadata index.

Check keyed digest equality/conflict before mutable content limits, but re-run
current target `Create` or scoped parent `View`/`Reply` authorization before an
exact replay returns content. A replay whose stored comment no longer belongs to
the requested target, for example after `CommentHistoryMover` moved it, is a
conflict. Revoked access must remain a concealed denial.

Force-deleting a comment runs in one transaction with its replies and attachment
cleanup; a veto on the comment or any reply rolls the whole deletion back, and
Media file removal runs only after the commit.

Soft deletion preserves a thread and is not privacy erasure. Authorized
comment-level anonymization is terminal and removes package-owned identity,
content, revisions, identifying moderation text, and attachment associations
while retaining structural and categorical audit facts. Actor-wide legal
erasure and retention scheduling remain consuming-application responsibilities.

Reconciliation is read-only by default. Production repair requires
`--repair --force`; review JSON output and unresolved hierarchy damage before
and after repair. Reconciliation never deletes evidence and must not emit user
events. Identity fingerprint mismatches are deployment blockers and are never
auto-repaired; correct the application-owned import or migration explicitly.
