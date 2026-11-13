# Changelog


All notable changes to `nvl/comments` are documented here.

## [Unreleased]

## [7.0.4] - 2026-11-13

### Fixed

- Match Unicode mention suggestions case-insensitively across SQLite (`nvl_utf8_lower`) and PostgreSQL (`COLLATE pg_c_utf8`), retain ownership columns on partial `SELECT` projections, and harden mutation lock and Doctor checks.

<!-- NVL GENERATED CHANGES START -->
### Changed

- harden package runtime safety, PostgreSQL portability and test performance
<!-- NVL GENERATED CHANGES END -->

## [7.0.3] - 2026-11-11

### Fixed

- Treat malformed comment, parent, revision and report identifiers, and malformed route bindings, as not found before they reach a UUID column, instead of a PostgreSQL type error that aborts the surrounding transaction.
- Keep stored rich comments and revisions readable after `rich_text.*` or `mentions.maximum_per_comment` limits are lowered; the limits now bound new input only.
- Cap `content.maximum_bytes` and `rich_text.maximum_bytes` at 65,535 bytes. Larger values are clamped at write time and reported by `nvl:comments:doctor` as invalid configuration.
- Add the `comments_target_pair_idx` index on `(commentable_type, commentable_id)` and plain equalities next to the byte-exact comparisons, so `withNvlVisibleCommentCount`, `whereHas('comments')` and `withCount('comments')` use an index instead of scanning the comments table per host row. Run the new migration.
- Treat malformed Media and association identifiers in `CommentAttachments::attach()` / `detach()` as not found, and malformed identifiers of UUID-keyed declarative mention resources as missing, before they reach a UUID column.
- Stop shipping the unused `database/tenancy-migrations` and `database/tenancy` directories and drop `tenant_id` from the models' fillable attributes. The Ownership adoption adapter still migrates installations that carry the column.
- Enforce `threading.maximum_replies_per_page` on reply pages and use `mentions.suggestion_limit` as the default of `CommentMentions::suggest()`. Remove `moderation.actionable_statuses` and `attachments.signed_url_lifetime`, which nothing read, and list the `metadata_values` and `mentions` table keys.
- Check declarative mention resources against the schema when they are first used instead of when the registry is built, so plain comments neither query the schema nor fail while the resource table is missing.
- Contain each `database` cache lock attempt in a savepoint inside a PostgreSQL transaction, so a contended lock is reported as busy instead of aborting the host transaction, and an expired lock can still be taken over.
- Remove replies through Eloquent when a comment is force-deleted so their Media attachments are cleaned up instead of being orphaned by the database cascade.
- Reject NUL bytes in comment bodies, rich text, locales and moderation, report and anonymization text instead of failing in PostgreSQL.
- Report the `(commentable_type, commentable_id)` index in `nvl:comments:doctor`.
- Rewrite `UPGRADING.md` under release headings and document digest key rotation.

<!-- NVL GENERATED CHANGES START -->
### Changed

- harden identifiers, locks, digests and target lookups
<!-- NVL GENERATED CHANGES END -->

## [7.0.2] - 2026-11-09

### Changed

- Refresh the inherited Ownership deletion API reference for ordinary models and native pivots.

<!-- NVL GENERATED CHANGES START -->
### Changed

- refresh inherited ownership deletion contracts
<!-- NVL GENERATED CHANGES END -->

## [7.0.1] - 2026-11-05

### Fixed

- Reject unsupported fields on rich document envelopes before creation or update, preserving the closed document contract without silently discarding client input.

<!-- NVL GENERATED CHANGES START -->
### Changed

- reject unsupported rich document fields
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

### Changed

- Align NVL peers and consumer contracts on the coordinated 7.x line. Review [UPGRADING.md](UPGRADING.md) for adoption and application-owned integration requirements.
- Expose pin state in safe reader projections and order thread/reply pages by visible pins while retaining chronological latest/batch-latest reads.

## [6.0.1] - 2026-10-21

### Changed

- Share configured Comment registration, queue capture and native backfill while retaining canonical target inheritance, attachment checks and hierarchy verification.
- Require optional Ownership >=6.0.1 when installed so new shared backfill callers cannot resolve an older runtime. Require Core >=6.0.1 for the shared model, queue or compatibility implementation.

## [6.0.0] - 2026-10-20

### Changed

- Resolve configured Comment subclasses and inherit canonical target ownership for roots, documents and maintenance. Retain target authorization and owner isolation for batch reads, mutations and queue work.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.


## [5.0.0] - 2026-10-08

### Added

- Added focused injectable contracts for all 26 selected public workflows, with native signatures and conditional defaults preserving host bindings.

- Add container-bound, SQL-authorized many-owner comment summaries with native
  identity maps, visible counts and latest rows bounded before hydration.
- Add query-free batch policy adapters and the optional
  `withNvlVisibleCommentCount` host scope, preserving selected columns and host
  scopes while enforcing canonical target, tenant and deletion boundaries.


### Changed

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Isolate owned cache and lock keys under `nvl:comments:`; preserve generic host entries and explicit store choices.

- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
- Store host owner identity using Laravel morph classes and isolate mutation lock keys.
- Preserve independently enabled routes and application authorization boundaries.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.2.2] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

## [2.2.1] - 2026-09-25

### Documentation

- Clarify adoption guidance for the published package.

## [2.2.0] - 2026-09-25

### Changed

- Prepare `nvl/comments` for independent Composer and Git publication; require `nvl/core` for shared Support and Data services.

## [2.0.1] - 2026-09-22

### Added

- Added canonical target tenant inheritance, membership-safe global mentions,
  tenant-bound attachments/cache validators, and package adoption.

### Fixed

- Reload latest-comment targets before authorization and apply the Delete query
  scope before selecting a comment for deletion.
- Share direct and latest-match deletion locks, revision checks, counter updates,
  rollback behavior, and after-commit events through one internal writer.
- Clear deletion/restoration audit identities belonging to an anonymized author
  while preserving other actors' identities and structural timestamps.

## [2.0.0] - 2026-08-29

### Added

- Added forward-only rich-document columns, normalized current mention rows,
  strict bounded version-one document compilation, server-owned label
  snapshots, and dedicated rich create/update Actions with revision,
  idempotency, concurrency, restore, deletion, and anonymization integration.

### Changed

- Established bounded Comment Actions, selectors, and owner traits as the 2.0
  consumer boundary; direct consumer Comment-model queries and relation
  aggregates now fail Suite audit.

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.
- Routed post-mutation comment reloads and active attachment association reads
  through authorized Comments and Media service boundaries instead of
  controller-owned persistence queries.

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

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
  relocated artifact consumers on Laravel 12–13, provenance
  checks, and production-only archive enforcement for the dependency closure.
- Updated the README, upgrade and security guidance, package skill, generated
  TypeScript declarations, package catalog, and public-contract baseline for
  the completed v1 API. Consumer guidance now covers immutable target/actor
  morph identities, Media production Doctor and private-owner authorization,
  installation/configuration, TypeScript checks, and application smoke tests.

## [1.0.0] - 2026-08-08

- Initial coordinated package-family release for Laravel 12–13.
