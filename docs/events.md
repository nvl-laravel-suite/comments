# NVL comments events

This document describes the implemented source behavior. Acceptance is exercised by the owning package suites and Core committed-event regression tests; current release execution evidence is tracked in consumer-readiness.md. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Actor/morph target identities, revisions and bounded mention identity changes. No body, private report text or mentioned-resource labels. added and removed each contain at most 100 CommentMentionChangeData objects.

Facts follow successful mutation branches; unchanged rich-body/mention differences produce no added/removed mention fact. Rejected authorization, revision or validation paths produce no committed mutation fact. No generic request deduplication is added.

CommentMentionsChanged validates list members and hard bounds at construction; actor and mention DTO fields are scalar identities.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [CommentChanged](#commentchanged) | 1 | Comment lifecycle/revision changed. |
| [CommentMentionsChanged](#commentmentionschanged) | 1 | Bounded mention identity diff persisted. |
| [CommentReactionChanged](#commentreactionchanged) | 1 | Reaction active state changed. |
| [CommentReported](#commentreported) | 1 | Moderation report persisted. |

### CommentChanged

`Nvl\Comments\Events\CommentChanged` · [source](../src/Events/CommentChanged.php) · event schema `1`.

Comment lifecycle/revision changed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$commentId` | `string` | public | `required` | — |
| `$targetType` | `string` | public | `required` | — |
| `$targetId` | `string` | public | `required` | — |
| `$operation` | `Nvl\Comments\Enums\CommentChangeOperation` | public | `required` | — |
| `$revision` | `int` | public | `required` | — |
| `$actor` | `Nvl\Comments\Data\CommentActorData` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$commentId` | `string` | — |
| `$targetType` | `string` | — |
| `$targetId` | `string` | — |
| `$operation` | `Nvl\Comments\Enums\CommentChangeOperation` | — |
| `$revision` | `int` | — |
| `$actor` | `Nvl\Comments\Data\CommentActorData` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/AnonymizeCommentAction.php](../src/Actions/AnonymizeCommentAction.php) | `$comment->getConnection()` |
| [Actions/ModerateCommentAction.php](../src/Actions/ModerateCommentAction.php) | `$comment->getConnection()` |
| [Actions/ResolveCommentReportAction.php](../src/Actions/ResolveCommentReportAction.php) | `$comment->getConnection()` |
| [Actions/RestoreCommentAction.php](../src/Actions/RestoreCommentAction.php) | `$comment->getConnection()` |
| [Actions/RestoreCommentRevisionAction.php](../src/Actions/RestoreCommentRevisionAction.php) | `$comment->getConnection()` |
| [Actions/UpdateCommentAction.php](../src/Actions/UpdateCommentAction.php) | `$comment->getConnection()` |
| [Actions/UpdateRichCommentAction.php](../src/Actions/UpdateRichCommentAction.php) | `$comment->getConnection()` |
| [Services/CommentCreationWriter.php](../src/Services/CommentCreationWriter.php) | `$comment->getConnection()` |
| [Services/CommentDeletionWriter.php](../src/Services/CommentDeletionWriter.php) | `$comment->getConnection()` |

### CommentMentionsChanged

`Nvl\Comments\Events\CommentMentionsChanged` · [source](../src/Events/CommentMentionsChanged.php) · event schema `1`.

Bounded mention identity diff persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$commentId` | `string` | public | `required` | — |
| `$targetType` | `string` | public | `required` | — |
| `$targetId` | `string` | public | `required` | — |
| `$revision` | `int` | public | `required` | — |
| `$added` | `array` | public | `required` | `list<Nvl\Comments\Data\CommentMentionChangeData>` |
| `$removed` | `array` | public | `required` | `list<Nvl\Comments\Data\CommentMentionChangeData>` |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$commentId` | `string` | — |
| `$targetType` | `string` | — |
| `$targetId` | `string` | — |
| `$revision` | `int` | — |
| `$added` | `array` | `list<Nvl\Comments\Data\CommentMentionChangeData>` |
| `$removed` | `array` | `list<Nvl\Comments\Data\CommentMentionChangeData>` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/AnonymizeCommentAction.php](../src/Actions/AnonymizeCommentAction.php) | `$comment->getConnection()` |
| [Actions/RestoreCommentRevisionAction.php](../src/Actions/RestoreCommentRevisionAction.php) | `$comment->getConnection()` |
| [Actions/UpdateRichCommentAction.php](../src/Actions/UpdateRichCommentAction.php) | `$comment->getConnection()` |
| [Services/CommentCreationWriter.php](../src/Services/CommentCreationWriter.php) | `$comment->getConnection()` |

### CommentReactionChanged

`Nvl\Comments\Events\CommentReactionChanged` · [source](../src/Events/CommentReactionChanged.php) · event schema `1`.

Reaction active state changed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$commentId` | `string` | public | `required` | — |
| `$type` | `string` | public | `required` | — |
| `$active` | `bool` | public | `required` | — |
| `$actor` | `Nvl\Comments\Data\CommentActorData` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$commentId` | `string` | — |
| `$type` | `string` | — |
| `$active` | `bool` | — |
| `$actor` | `Nvl\Comments\Data\CommentActorData` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/SetCommentReactionAction.php](../src/Actions/SetCommentReactionAction.php) | `$comment->getConnection()` |

### CommentReported

`Nvl\Comments\Events\CommentReported` · [source](../src/Events/CommentReported.php) · event schema `1`.

Moderation report persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$commentId` | `string` | public | `required` | — |
| `$reportId` | `string` | public | `required` | — |
| `$actor` | `Nvl\Comments\Data\CommentActorData` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$commentId` | `string` | — |
| `$reportId` | `string` | — |
| `$actor` | `Nvl\Comments\Data\CommentActorData` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/ReportCommentAction.php](../src/Actions/ReportCommentAction.php) | `$comment->getConnection()` |

## Referenced payload types

Native event field types are listed above; nested declared fields and backed enum values follow. Private captured envelopes are included because serialized/queued objects retain them. Dates use `Carbon\CarbonImmutable`. Spatie Data serialization can also carry its protected transformation metadata; immutable serialized payload graphs are checked by the C4 contract suite.

### CommentActorData

`Nvl\Comments\Data\CommentActorData` · [source](../src/Data/CommentActorData.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$type` | `?string` | — |
| `$id` | `?string` | — |
| `$system` | `bool` | — |

### CommentMentionChangeData

`Nvl\Comments\Data\CommentMentionChangeData` · [source](../src/Data/CommentMentionChangeData.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$resourceAlias` | `string` | — |
| `$resourceId` | `string` | — |
| `$tokenId` | `string` | — |

### CommentChangeOperation

`Nvl\Comments\Enums\CommentChangeOperation` · [source](../src/Enums/CommentChangeOperation.php).

Backed string values: `Created = created`, `Updated = updated`, `Deleted = deleted`, `Restored = restored`, `Anonymized = anonymized`, `Moderated = moderated`, `ReportReviewed = report_reviewed`, `RevisionRestored = revision_restored`.

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.

## Consumer event assertions

Use the canonical event class listed in the catalog for `Event::fake([...])` and `Event::assertDispatched(...)`. Laravel fake filters compare the emitted class name; an old alias import does not rename that canonical object. Legacy exact listeners are bridged at delivery time through Laravel’s native dispatcher. Keep compatibility listener tests on their exact legacy name, and migrate suffix-specific wildcards to canonical names.

