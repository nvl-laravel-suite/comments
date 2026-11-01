---
name: nvl-comments
description: Use when integrating nvl/comments discussion targets, plain or rich comments, audience-safe reads, moderation, reactions, mentions, revision history, anonymity or optional Media attachments.
---

# NVL Comments

Use native PHP 8.4 / Laravel 13 domain services. The host owns controllers,
routes, requests, resources, actor presentation, editor UI and frontend types.

## References

- [Installation](references/installation.md): dependencies, configuration, locks, migrations and publication.
- [Native API](references/api.md): exact public methods, enums, contracts and domain values.
- [Usage](references/usage.md): target/policy setup, payload examples, privacy and optional integrations.

## Select a native capability

| Need | Service |
| --- | --- |
| Create plain/rich, edit, delete/restore, restore revision, anonymize | `CommentWriter` |
| Page/latest, owner summaries, revisions and reports | `CommentReader` |
| Approve/reject/hide/spam, report or resolve report | `CommentModeration` |
| Set/remove a configured reaction | `CommentReactions` |
| Resolve mentions or suggest registered resources | `CommentMentions` |
| Attach/detach native assets | `CommentAttachments` |

## Canonical target and host policy

Register stable target aliases and `CommentTargetResolver` implementations in
`nvl-comments.targets`. Keep target class/owner capability registration server-owned.
Services reload actor, target and comment facts on writer connections with host
scopes and native Ownership intact; submitted identities never establish authority.

Choose `CommentAuthorization`, `CommentQueryScope` and `CommentBatchQueryScope`
together. Imperative permission checks do not replace SQL visibility predicates.
Default policy is conservative public discussion, not a private-thread/moderator
policy. `SystemActor::Background` is denied by default; anonymous creation needs
explicit opt-in. Public, Member and Management audiences are policy boundaries.

## Native mutation and privacy contracts

- Create accepts closed plain/Markdown keys; rich documents use `createRich`.
- Updates and moderation carry `expected_revision`; delete/restore take it separately.
- Preserve null/omission for optional locale, tags and metadata as the operation defines.
- Keep one creation `idempotency_key` for the same retried input.
- Reports accept reason/details; they do not accept a caller idempotency key.
- Reactions request an explicit enabled/removed state rather than a toggle.
- Rich documents, mentions and metadata retain their registered schemas/allowlists.

Mutations return native models for trusted host orchestration. Never serialize a
raw model as public actor identity. Reader output is audience-safe arrays/paginators;
plain documents are null with no mentions. Revision metadata uses visible
namespace/value projection. Owner-batch latest entries remain text snippets.

## Batches and optional integrations

Query scopes nest inside canonical target predicates so loose OR clauses cannot
escape into another target. Preserve bounded resource batches, actor-aware reply
counts and correlated visible-comment counts. Batch admission/SQL callbacks must
not issue per-row queries. Explicit actor/audience also applies to model count scopes.

Mention resources require `nvl-comments.mentions.enabled` and declared resolvers
or model/search/exposure/authorization configuration. Optional Media attachments
use `attachments.enabled` (null detection, false disable, true require), native
Media permissions and slots. Ordinary comments work without Media.

## Models, transactions and checks

Select `nvl-comments.models.comment` before boot with a concrete Comment subclass.
Keep native UUIDs, casts, rich-document/content hooks and configured storage.
Ownership is unscoped or inherited through canonical `commentable`; fixed history,
reports and reaction models are not replaceable roots. Added columns need host migrations.

Keep mutation locks, source-connection transactions, revisions, idempotency digests,
moderation counters and outer-commit events intact. Test audience privacy, actor/target
substitution, retries, parent/depth rules, rich mentions, optional asset absence,
revision restoration/anonymization and rollback. Use [usage](references/usage.md)
for actual payloads; avoid inventing routes or blanket policy grants.
