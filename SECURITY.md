# Security Policy

Coordinated security fixes are provided for the current `1.x` release line on
PHP 8.3–8.4 and Laravel 12–13. Comments also supports PHP 8.5 at runtime and
tests it in CI, but PHP 8.5 is not yet in the coordinated package-family
security promise because the mandatory Media and Filterable dependencies
currently publish security support through PHP 8.4. Promote the promise after
those dependency policies are aligned. Report vulnerabilities privately
through the repository host's security-advisory feature.

Include the target alias, audience, visibility, actor type, Action or route,
Media visibility, and impact. Do not include personal production content,
credentials, idempotency secrets, or private asset URLs.

## Deployment requirements

- Keep public, member, and management routes disabled until their middleware,
  actor resolver, target resolver, query scope, authorization, and author
  presentation are tested. Run strict Doctor before enabling them, then run
  consumer HTTP smoke tests because structural binding readiness cannot prove
  application-specific policy behavior.
- Derive membership and tenancy from the canonical target and consumer policy.
  Never accept a tenant/member scope or polymorphic model class from request
  input.
- Register stable morph aliases before the first write. HTTP target aliases are
  not persisted type aliases: target models and Eloquent actors use
  `getMorphClass()`, while non-model authenticated principals default to their
  class name. Treat those persisted types as immutable.
- Treat HTTP target IDs as one percent-encoded path segment. The package accepts
  valid segment characters except `/`; resolved canonical IDs remain bounded to
  255 UTF-8 characters. Use an application-owned slash-free mapping when an
  external identifier contains `/`.
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
- Apply the operation-aware `CommentQueryScope` before filters, pagination,
  counts, and every identifier resolution. Treat inaccessible and cross-target
  public/member identifiers as 404 to avoid enumeration.
- Keep public GET output viewer-independent. Never attach authenticated user
  state to public projections, public attachment lists, or shared-cache
  responses.
- Never expose stored actor IDs, reporter identity/details, moderation facts,
  visibility, viewer abilities, revision internals, Media IDs, disk, path,
  checksum, uploader, or conversion details through public output.
- Treat `CommentAbility::ViewIdentity` as a distinct privileged capability.
  Granting moderation must not implicitly expose actor, reporter, reviewer, or
  lifecycle identities.
- Author presenters must batch their lookups and return only display name,
  avatar, label, and an audience-scoped opaque key. Authorization callbacks
  used to build abilities or management projections must remain query-free or
  use request-scoped batching.
- Render Markdown through a safe allowlist, rate-limit every mutation surface,
  and bound page, body, tag, metadata, revision, and attachment sizes.
- Keep mutation locking enabled and configure one canonical `LockProvider`
  cache shared by every HTTP process, worker, scheduler, and reconciliation
  process. Prefer Redis or a shared database lock table. `array`, `null`, and
  `failover` are never safe lock domains. File locking is single-host only and
  requires the explicit `comments.mutation_lock.allow_local_store=true`
  acknowledgement. Strict Doctor must pass after cache or topology changes.

## Attachments

Private Media association requires both Comments authorization and Media
authorization for the canonical actor/comment/Media tuple. A Media UUID is not
an ownership capability. Keep public Media attachment disabled unless public
asset reuse is intentional. Keep `maximum_file_bytes` as a positive integer and
set it to the largest object the application is prepared to authorize and
deliver.

The default Media policy permits public visibility or exact uploader ownership;
it does not make a private comment attachment visible to an anonymous public
reader or an unrelated moderator. Bind a `MediaAuthorization` that explicitly
handles the canonical `Comment` owner context and run
`php artisan nvl:media:doctor --production --strict --format=json` whenever
attachments are enabled. Comments Doctor is not a substitute for Media's
production readiness audit.

Comments, Media, and Media associations must share a connection for atomic
writes. Preserve the comment→sorted Media→database-row lock order. Detach only
the selected association; never delete the underlying Media record or another
collection. Signed asset URLs contain only the association identity and never
the Media UUID, uploader, storage path, or conversion internals. Signed asset
delivery responses and all public mutation/member/management attachment
responses must remain private/no-store. Treat each signed URL as a short-lived
bearer capability: authorize the canonical Comment owner at issuance, then
validate the signature and live association again at delivery. Keep signed
delivery routes enabled for every HTTP attachment mutation surface; route
preflight must fail before any association write. Public attachment metadata
must use a deterministic fallback locale, and its shared-cache lifetime must
remain shorter than the signed capability lifetime.

Disabling attachments removes their routes and ordinary Media dependency; it
does not erase historical associations. Keep the complete same-connection Media
schema available until historical comment associations are explicitly cleaned
up. Treat a strict Doctor `attachments.disabled_state_ready` failure as a
deployment blocker.

## Lifecycle and privacy

Use optimistic `expectedRevision` checks for all lifecycle, comment moderation,
and report-review mutations. Preserve the unique constraints for creation
idempotency, reactions, reports, and associations. The keyed idempotency digest
protects canonical request comparison; rotate application secrets only with an
explicit policy for outstanding idempotency keys.

Check keyed digest equality/conflict before mutable content limits, but re-run
current target `Create` or scoped parent `View`/`Reply` authorization before an
exact replay returns content. Revoked access must remain a concealed denial.

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
