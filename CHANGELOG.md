# Changelog

All notable changes to `nvl/comments` are documented here.

## [Unreleased]

- Registered migration publication through Laravel's timestamp-aware API and made Doctor warn when automatic vendor loading overlaps a published host copy.
- Completed the unreleased v1 headless comments domain with string-key-safe
  polymorphic targets, bounded reply trees, source locale, optimistic
  revisions, moderation, lifecycle audit fields, reactions, reports, and
  private Media attachments.
- Split response contracts into public, member, and management projections,
  with dedicated safe author, attachment, revision, abilities, and reaction
  DTOs. Deleted and anonymized public/member rows now serialize as structural
  tombstones with no content, identity, policy, or storage leakage.
- Added `CommentAudience`, the complete `CommentAbility` set,
  `CommentAccessService`, trusted `CommentQueryScope`, batched
  `CommentAuthorPresenter`, and request actor resolution. Public reads are
  viewer-independent; member reads are viewer-aware; management identity
  exposure requires the dedicated `ViewIdentity` authorization independently
  from moderation. Actor DTOs now reject partial, blank, invalid UTF-8,
  oversized, reserved-system, and non-scalar authenticated identities.
- Added an independently configurable, disabled-by-default member HTTP group
  with list/show/create/update/delete/restore, reaction, report, attachment,
  revision-history, and revision-restore operations.
- Expanded target routes from a restrictive ASCII/191-character regex to
  Laravel's non-slash single-segment contract, with regression coverage for
  percent-encoded UTF-8/spaces and 255-character domain identifiers.
- Expanded target-scoped management APIs with actionable comment/report queues,
  restoration, terminal anonymization, attachment management, and revision
  history/restoration.
- Added UUID creation idempotency with a keyed canonical digest, tombstone
  replay, conflict detection, a database uniqueness boundary, and
  reload-after-conflict concurrency handling.
- Required `expectedRevision` for content, deletion, restoration,
  anonymization, revision restoration, comment moderation, and report review.
- Added audited restore and irreversible anonymization flows, including
  attachment cleanup, revision scrubbing, exact parent-counter updates, and
  terminal restoration rejection.
- Added lifetime and open report counters, no-op-safe report transitions,
  actionable moderation queues including deleted evidence, bounded filters,
  and deterministic moderation/report sorts.
- Added `nvl:comments:reconcile`, which is dry-run by default and audits or
  lock-safely repairs reply, reaction, report, open-report, root, and depth
  drift while diagnosing unsafe hierarchy, missing targets, and invalid
  attachment associations. It now also diagnoses comment/reaction/report
  fingerprint drift and refuses ambiguous identity or dependent counter repair.
- Replaced free-form change operations with `CommentChangeOperation` and made
  versioned privacy-bounded events dispatch only after commit. Retries, no-ops,
  rollbacks, and reconciliation do not replay user events.
- Standardized the comment→sorted Media→database-row lock order, preserved all
  uniqueness constraints, and expanded Doctor for the schema, indexes, route
  surfaces, actor resolution, query scoping, author presentation, policy
  readiness, and Media connection requirements.
- Kept transaction-scoped mutation locks through nested savepoint rollbacks and
  released them on root commit or rollback across the full Laravel 12–13 support
  range, including Laravel 12.0 before native rollback callbacks were added.
- Made target, actor, reaction, status, and visibility identity byte-exact across
  database collations with length-delimited fingerprints, immutable canonical
  migrations, and strict configuration/schema readiness checks. Cross-connection
  target creation now rejects uncommitted targets and relationship existence
  queries fail explicitly instead of producing invalid SQL.
- Added an exact configurable attachment byte limit, removed attachment routes
  and ordinary Media dependencies when the feature is disabled, audited
  historical disabled-attachment state, and forced local binary delivery to
  remain private/no-store.
- Applied the operation-aware query scope to every mutation/history identifier,
  made visible reply counts scope-derived for all audiences, and concealed
  cross-target/inaccessible identifiers as 404 outside management permission
  failures.
- Added association-only signed attachment delivery URLs, no-store error
  handling, terminal moderation rejection after anonymization, report-review
  revision locking, and anonymized-association reconciliation diagnostics.
- Hardened orphan-target concealment, required authenticated/rate-limited
  management routes, preflighted signed attachment delivery before writes,
  removed active and inactive associations during anonymization, supported
  soft-deleted Media detachment, and rolled every observer-vetoed persistence
  operation back without events or counter drift.
- Preserved granular ability independence during post-mutation projection,
  replayed idempotency keys before mutable content-policy checks, concealed
  reply denials outside management, and made shared public attachment metadata
  locale-deterministic with cache lifetimes capped below signed asset
  capabilities.
- Added package-level privacy, lifecycle, idempotency, moderation,
  reconciliation, route, constant-query, and event regression coverage.
- Aligned HTTP target identifiers with the 255-character UTF-8 domain contract
  for one percent-encoded path segment, and added clean source plus sealed,
  relocated artifact consumers on Laravel 12–13, MariaDB coverage, provenance
  checks, and production-only archive enforcement for the dependency closure.
- Updated the README, upgrade and security guidance, package skill, generated
  TypeScript declarations, package catalog, and public-contract baseline for
  the completed v1 API. Consumer guidance now covers immutable target/actor
  morph identities, Media production Doctor and private-owner authorization,
  installation/configuration, TypeScript checks, and application smoke tests.

## [1.0.0] - Unreleased

- Initial coordinated package-family release for Laravel 12–13.
