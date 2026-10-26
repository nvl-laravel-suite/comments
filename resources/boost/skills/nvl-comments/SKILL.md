---
name: nvl-comments
description: Use for native NVL Comments reads, writes, moderation, reactions, mentions, and optional Media attachments.
---

# NVL Comments

Use the package's native domain services. Host applications own controllers, routes, request validation, API resources, actor presentation, and frontend types. The package does not register HTTP endpoints or publish TypeScript contracts.

## Configure

Register target aliases in `nvl-comments.targets` with `CommentTargetResolver` implementations. Bind `CommentAuthorization`, `CommentQueryScope`, and `CommentBatchQueryScope` to host policy when the conservative default does not cover the application. Every policy method receives an explicit `Authenticatable|SystemActor|PublicActor` actor; do not read the current guard or infer a system permission. A persisted human actor must keep its registered class, key, table, and connection. `SystemActor::Background` is an identity and is denied by default. `PublicActor::Anonymous` is available only for supported public operations and anonymous creation must be enabled explicitly.

Use one migration source: vendor loading or published host migrations. Run `php artisan nvl:comments:doctor` after configuration. Ownership, visibility, and moderation are checked against canonically reloaded targets and comments. Never take target class, owner scope, audience, or actor identity directly from an untrusted request.

## Services

- `CommentWriter`: `create`, `createRich`, `update`, `updateRich`, `delete`, `restore`, `restoreRevision`, `anonymize`.
- `CommentReader`: `paginate`, `forOwners`, `latest`, `revisions`, `reports`. Results are audience-safe arrays or paginators of arrays. Owner batches return ordered `order` and `values` and have deterministic bounded reads.
- `CommentReactions`: `add`, `remove` for configured reaction types.
- `CommentModeration`: `moderate`, `report`, `resolveReport` with host policy.
- `CommentMentions`: `resolve`, `suggest` for explicitly registered mention resources.
- `CommentAttachments`: `attach`, `detach` through optional native Media authorization and slots.

Pass native Eloquent models, enums, scalars, and shaped arrays. Mutations return package models for trusted host code; map consumer-facing resources yourself and never expose stored actor identity from a raw model. Use an expected revision for lifecycle changes. Rich documents and metadata use configured allowlists. Keep idempotency keys stable for retried creation/reporting. Requested Media operations fail explicitly when the provider is absent; ordinary comments continue without Media.

Run the owning Pest suite after changing package behavior, and keep audience, privacy, revision, idempotency, canonical identity, and bounded batch assertions active.
