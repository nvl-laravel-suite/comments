# Public API: nvl/comments

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Comments\Contracts\CommentAttachmentLifecycle`
- `Nvl\Comments\Contracts\CommentAuthorization`
- `Nvl\Comments\Contracts\CommentBatchQueryScope`
- `Nvl\Comments\Contracts\CommentMentionResourceAuthorization`
- `Nvl\Comments\Contracts\CommentMentionResourceResolver`
- `Nvl\Comments\Contracts\CommentMentionUrlResolver`
- `Nvl\Comments\Contracts\CommentMetadataSchema`
- `Nvl\Comments\Contracts\CommentQueryScope`
- `Nvl\Comments\Contracts\CommentTargetResolver`
- `Nvl\Comments\Contracts\HasComments`
- `Nvl\Comments\Contracts\ViewerIndependentCommentMentionResource`
- `Nvl\Comments\Database\Factories\CommentFactory`
- `Nvl\Comments\Database\Factories\CommentMentionFactory`
- `Nvl\Comments\Database\Factories\CommentMetadataValueFactory`
- `Nvl\Comments\Database\Factories\CommentReactionFactory`
- `Nvl\Comments\Database\Factories\CommentReportFactory`
- `Nvl\Comments\Database\Factories\CommentRevisionFactory`
- `Nvl\Comments\Definitions\CommentMetadataField`
- `Nvl\Comments\Enums\CommentAbility`
- `Nvl\Comments\Enums\CommentAudience`
- `Nvl\Comments\Enums\CommentChangeOperation`
- `Nvl\Comments\Enums\CommentFormat`
- `Nvl\Comments\Enums\CommentMentionState`
- `Nvl\Comments\Enums\CommentMetadataValueType`
- `Nvl\Comments\Enums\CommentReportStatus`
- `Nvl\Comments\Enums\CommentStatus`
- `Nvl\Comments\Enums\CommentVisibility`
- `Nvl\Comments\Enums\CommentsResponseCode`
- `Nvl\Comments\Events\CommentChanged`
- `Nvl\Comments\Events\CommentMentionsChanged`
- `Nvl\Comments\Events\CommentReactionChanged`
- `Nvl\Comments\Events\CommentReported`
- `Nvl\Comments\Exceptions\CommentAttachmentDeliveryUnavailableException`
- `Nvl\Comments\Exceptions\CommentBatchReadException`
- `Nvl\Comments\Exceptions\CommentIdempotencyConflictException`
- `Nvl\Comments\Exceptions\CommentMutationBusyException`
- `Nvl\Comments\Exceptions\CommentMutationLockConfigurationException`
- `Nvl\Comments\Exceptions\CommentTargetNotFoundException`
- `Nvl\Comments\Exceptions\CommentsException`
- `Nvl\Comments\Exceptions\InvalidCommentLifecycleException`
- `Nvl\Comments\Exceptions\InvalidCommentMutationException`
- `Nvl\Comments\Exceptions\StaleCommentException`
- `Nvl\Comments\Models\Comment`
- `Nvl\Comments\Models\CommentMention`
- `Nvl\Comments\Models\CommentMetadataValue`
- `Nvl\Comments\Models\CommentReaction`
- `Nvl\Comments\Models\CommentReport`
- `Nvl\Comments\Models\CommentRevision`
- `Nvl\Comments\Services\CommentAttachments`
- `Nvl\Comments\Services\CommentMentions`
- `Nvl\Comments\Services\CommentModelRegistry`
- `Nvl\Comments\Services\CommentModeration`
- `Nvl\Comments\Services\CommentReactions`
- `Nvl\Comments\Services\CommentReader`
- `Nvl\Comments\Services\CommentWriter`
- `Nvl\Comments\Services\ConfiguredCommentAuthorization`
- `Nvl\Comments\Support\CommentIdentity`
- `Nvl\Comments\Traits\InteractsWithComments`
- `Nvl\Comments\ValueObjects\CommentMentionContext`

## Nvl\Comments\Contracts\CommentAttachmentLifecycle

```text
interface Nvl\Comments\Contracts\CommentAttachmentLifecycle
public abstract function detach(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $id, Nvl\Comments\Models\Comment $comment): int
public abstract function ids(Nvl\Comments\Models\Comment $comment, bool $lock=false): array
@return list<string>
public abstract function stateRequired(Nvl\Comments\Models\Comment $comment): bool
public abstract function synchronize(array $ids, Closure $callback): Nvl\Comments\Models\Comment
@param list<string> $ids
@param Closure(): Comment $callback
resolved @param Closure(): Nvl\Comments\Models\Comment $callback
```

## Nvl\Comments\Contracts\CommentAuthorization

```text
interface Nvl\Comments\Contracts\CommentAuthorization
public abstract function allows(Nvl\Comments\Enums\CommentAbility $ability, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, ?Nvl\Comments\Models\Comment $comment=null, ?Illuminate\Database\Eloquent\Model $target=null, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public, array $context=[]): bool
@param array<string, mixed> $context
```

## Nvl\Comments\Contracts\CommentBatchQueryScope

```text
interface Nvl\Comments\Contracts\CommentBatchQueryScope
public abstract function authorizeOwners(Nvl\Support\Owners\OwnerBatch $owners, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience): void
public abstract function scopeBatchComments(Illuminate\Database\Eloquent\Builder $query, Nvl\Support\Owners\OwnerBatch $owners, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience): void
@param Builder<Comment> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\Comment> $query
public abstract function scopeHostComments(Illuminate\Database\Eloquent\Builder $query, Illuminate\Database\Eloquent\Model $ownerPrototype, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience): void
@param Builder<Comment> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\Comment> $query
```

## Nvl\Comments\Contracts\CommentMentionResourceAuthorization

```text
interface Nvl\Comments\Contracts\CommentMentionResourceAuthorization
public abstract function scope(Illuminate\Database\Eloquent\Builder $query, Nvl\Comments\ValueObjects\CommentMentionContext $context): void
@param Builder<*> $query
resolved @param Illuminate\Database\Eloquent\Builder<*> $query
```

## Nvl\Comments\Contracts\CommentMentionResourceResolver

```text
interface Nvl\Comments\Contracts\CommentMentionResourceResolver
public abstract function resolve(Nvl\Comments\ValueObjects\CommentMentionContext $context, array $ids): Illuminate\Support\Collection
@param list<string> $ids
@return Collection<int, array<string, mixed>>
resolved @return Illuminate\Support\Collection<int, array<string, mixed>>
public abstract function suggest(Nvl\Comments\ValueObjects\CommentMentionContext $context, string $query, int $limit): Illuminate\Support\Collection
@return Collection<int, array<string, mixed>>
resolved @return Illuminate\Support\Collection<int, array<string, mixed>>
```

## Nvl\Comments\Contracts\CommentMentionUrlResolver

```text
interface Nvl\Comments\Contracts\CommentMentionUrlResolver
public abstract function resolve(Illuminate\Database\Eloquent\Model $resource, Nvl\Comments\ValueObjects\CommentMentionContext $context): ?string
```

## Nvl\Comments\Contracts\CommentMetadataSchema

```text
interface Nvl\Comments\Contracts\CommentMetadataSchema
public abstract function fields(): array
@return list<CommentMetadataField>
resolved @return list<Nvl\Comments\Definitions\CommentMetadataField>
public abstract function namespace(): string
```

## Nvl\Comments\Contracts\CommentQueryScope

```text
interface Nvl\Comments\Contracts\CommentQueryScope
public abstract function scopeComments(Illuminate\Database\Eloquent\Builder $query, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Illuminate\Database\Eloquent\Model $target, Nvl\Comments\Enums\CommentAudience $audience, Nvl\Comments\Enums\CommentAbility $ability): void
@param Builder<Comment> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\Comment> $query
```

## Nvl\Comments\Contracts\CommentTargetResolver

```text
interface Nvl\Comments\Contracts\CommentTargetResolver
public abstract function alias(): string
public abstract function resolve(string $identifier): ?Illuminate\Database\Eloquent\Model
```

## Nvl\Comments\Contracts\HasComments

```text
interface Nvl\Comments\Contracts\HasComments
```

## Nvl\Comments\Contracts\ViewerIndependentCommentMentionResource

```text
interface Nvl\Comments\Contracts\ViewerIndependentCommentMentionResource
```

## Nvl\Comments\Database\Factories\CommentFactory

```text
final class Nvl\Comments\Database\Factories\CommentFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<Comment>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Comments\Models\Comment>
public function definition(): array
@return array<model-property<Comment>, mixed>
resolved @return array<model-property<Nvl\Comments\Models\Comment>, mixed>
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function modelName(): string
```

## Nvl\Comments\Database\Factories\CommentMentionFactory

```text
final class Nvl\Comments\Database\Factories\CommentMentionFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<CommentMention>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Comments\Models\CommentMention>
public function definition(): array
@return array<model-property<CommentMention>, mixed>
resolved @return array<model-property<Nvl\Comments\Models\CommentMention>, mixed>
public function forComment(Nvl\Comments\Models\Comment $parent): static
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function forResource(string $alias, string $identifier, string $label): static
```

## Nvl\Comments\Database\Factories\CommentMetadataValueFactory

```text
final class Nvl\Comments\Database\Factories\CommentMetadataValueFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<CommentMetadataValue>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Comments\Models\CommentMetadataValue>
public function definition(): array
@return array<model-property<CommentMetadataValue>, mixed>
resolved @return array<model-property<Nvl\Comments\Models\CommentMetadataValue>, mixed>
public function forComment(Nvl\Comments\Models\Comment $parent): static
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
```

## Nvl\Comments\Database\Factories\CommentReactionFactory

```text
final class Nvl\Comments\Database\Factories\CommentReactionFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<CommentReaction>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Comments\Models\CommentReaction>
public function definition(): array
@return array<model-property<CommentReaction>, mixed>
resolved @return array<model-property<Nvl\Comments\Models\CommentReaction>, mixed>
public function forComment(Nvl\Comments\Models\Comment $parent): static
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
```

## Nvl\Comments\Database\Factories\CommentReportFactory

```text
final class Nvl\Comments\Database\Factories\CommentReportFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<CommentReport>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Comments\Models\CommentReport>
public function definition(): array
@return array<model-property<CommentReport>, mixed>
resolved @return array<model-property<Nvl\Comments\Models\CommentReport>, mixed>
public function forComment(Nvl\Comments\Models\Comment $parent): static
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
```

## Nvl\Comments\Database\Factories\CommentRevisionFactory

```text
final class Nvl\Comments\Database\Factories\CommentRevisionFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<CommentRevision>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Comments\Models\CommentRevision>
public function definition(): array
@return array<model-property<CommentRevision>, mixed>
resolved @return array<model-property<Nvl\Comments\Models\CommentRevision>, mixed>
public function forComment(Nvl\Comments\Models\Comment $parent): static
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
```

## Nvl\Comments\Definitions\CommentMetadataField

```text
final readonly class Nvl\Comments\Definitions\CommentMetadataField
public function __construct(string $name, string $storageKey, Nvl\Comments\Enums\CommentMetadataValueType $type, bool $nullable, bool $mutable, bool $queryable, array $visibleTo, ?int $maximumStringLength=null): mixed
@param array<array-key, mixed> $visibleTo
public readonly ?int $maximumStringLength
public readonly bool $mutable
public readonly string $name
public readonly bool $nullable
public readonly bool $queryable
public readonly string $storageKey
public readonly Nvl\Comments\Enums\CommentMetadataValueType $type
public readonly array $visibleTo
@var list<CommentAudience>
resolved @var list<Nvl\Comments\Enums\CommentAudience>
```

## Nvl\Comments\Enums\CommentAbility

```text
enum Nvl\Comments\Enums\CommentAbility implements BackedEnum, UnitEnum: string
public const Nvl\Comments\Enums\CommentAbility Anonymize=Nvl\Comments\Enums\CommentAbility::Anonymize="anonymize"
public const Nvl\Comments\Enums\CommentAbility Attach=Nvl\Comments\Enums\CommentAbility::Attach="attach"
public const Nvl\Comments\Enums\CommentAbility Create=Nvl\Comments\Enums\CommentAbility::Create="create"
public const Nvl\Comments\Enums\CommentAbility Delete=Nvl\Comments\Enums\CommentAbility::Delete="delete"
public const Nvl\Comments\Enums\CommentAbility Detach=Nvl\Comments\Enums\CommentAbility::Detach="detach"
public const Nvl\Comments\Enums\CommentAbility List=Nvl\Comments\Enums\CommentAbility::List="list"
public const Nvl\Comments\Enums\CommentAbility Moderate=Nvl\Comments\Enums\CommentAbility::Moderate="moderate"
public const Nvl\Comments\Enums\CommentAbility React=Nvl\Comments\Enums\CommentAbility::React="react"
public const Nvl\Comments\Enums\CommentAbility Reply=Nvl\Comments\Enums\CommentAbility::Reply="reply"
public const Nvl\Comments\Enums\CommentAbility Report=Nvl\Comments\Enums\CommentAbility::Report="report"
public const Nvl\Comments\Enums\CommentAbility Restore=Nvl\Comments\Enums\CommentAbility::Restore="restore"
public const Nvl\Comments\Enums\CommentAbility RestoreRevision=Nvl\Comments\Enums\CommentAbility::RestoreRevision="restore_revision"
public const Nvl\Comments\Enums\CommentAbility Update=Nvl\Comments\Enums\CommentAbility::Update="update"
public const Nvl\Comments\Enums\CommentAbility View=Nvl\Comments\Enums\CommentAbility::View="view"
public const Nvl\Comments\Enums\CommentAbility ViewHistory=Nvl\Comments\Enums\CommentAbility::ViewHistory="view_history"
public const Nvl\Comments\Enums\CommentAbility ViewIdentity=Nvl\Comments\Enums\CommentAbility::ViewIdentity="view_identity"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Enums\CommentAudience

```text
enum Nvl\Comments\Enums\CommentAudience implements BackedEnum, UnitEnum: string
public const Nvl\Comments\Enums\CommentAudience Management=Nvl\Comments\Enums\CommentAudience::Management="management"
public const Nvl\Comments\Enums\CommentAudience Member=Nvl\Comments\Enums\CommentAudience::Member="member"
public const Nvl\Comments\Enums\CommentAudience Public=Nvl\Comments\Enums\CommentAudience::Public="public"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Enums\CommentChangeOperation

```text
enum Nvl\Comments\Enums\CommentChangeOperation implements BackedEnum, UnitEnum: string
public const Nvl\Comments\Enums\CommentChangeOperation Anonymized=Nvl\Comments\Enums\CommentChangeOperation::Anonymized="anonymized"
public const Nvl\Comments\Enums\CommentChangeOperation Created=Nvl\Comments\Enums\CommentChangeOperation::Created="created"
public const Nvl\Comments\Enums\CommentChangeOperation Deleted=Nvl\Comments\Enums\CommentChangeOperation::Deleted="deleted"
public const Nvl\Comments\Enums\CommentChangeOperation Moderated=Nvl\Comments\Enums\CommentChangeOperation::Moderated="moderated"
public const Nvl\Comments\Enums\CommentChangeOperation ReportReviewed=Nvl\Comments\Enums\CommentChangeOperation::ReportReviewed="report_reviewed"
public const Nvl\Comments\Enums\CommentChangeOperation Restored=Nvl\Comments\Enums\CommentChangeOperation::Restored="restored"
public const Nvl\Comments\Enums\CommentChangeOperation RevisionRestored=Nvl\Comments\Enums\CommentChangeOperation::RevisionRestored="revision_restored"
public const Nvl\Comments\Enums\CommentChangeOperation Updated=Nvl\Comments\Enums\CommentChangeOperation::Updated="updated"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Enums\CommentFormat

```text
enum Nvl\Comments\Enums\CommentFormat implements BackedEnum, UnitEnum: string
public const Nvl\Comments\Enums\CommentFormat Markdown=Nvl\Comments\Enums\CommentFormat::Markdown="markdown"
public const Nvl\Comments\Enums\CommentFormat Plain=Nvl\Comments\Enums\CommentFormat::Plain="plain"
public const Nvl\Comments\Enums\CommentFormat RichText=Nvl\Comments\Enums\CommentFormat::RichText="rich_text"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Enums\CommentMentionState

```text
enum Nvl\Comments\Enums\CommentMentionState implements BackedEnum, UnitEnum: string
public const Nvl\Comments\Enums\CommentMentionState Missing=Nvl\Comments\Enums\CommentMentionState::Missing="missing"
public const Nvl\Comments\Enums\CommentMentionState Resolved=Nvl\Comments\Enums\CommentMentionState::Resolved="resolved"
public const Nvl\Comments\Enums\CommentMentionState Restricted=Nvl\Comments\Enums\CommentMentionState::Restricted="restricted"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Enums\CommentMetadataValueType

```text
enum Nvl\Comments\Enums\CommentMetadataValueType implements BackedEnum, UnitEnum: string
public const Nvl\Comments\Enums\CommentMetadataValueType Boolean=Nvl\Comments\Enums\CommentMetadataValueType::Boolean="boolean"
public const Nvl\Comments\Enums\CommentMetadataValueType Integer=Nvl\Comments\Enums\CommentMetadataValueType::Integer="integer"
public const Nvl\Comments\Enums\CommentMetadataValueType String=Nvl\Comments\Enums\CommentMetadataValueType::String="string"
public const Nvl\Comments\Enums\CommentMetadataValueType Uuid=Nvl\Comments\Enums\CommentMetadataValueType::Uuid="uuid"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Enums\CommentReportStatus

```text
enum Nvl\Comments\Enums\CommentReportStatus implements BackedEnum, UnitEnum: string
public const Nvl\Comments\Enums\CommentReportStatus Dismissed=Nvl\Comments\Enums\CommentReportStatus::Dismissed="dismissed"
public const Nvl\Comments\Enums\CommentReportStatus Open=Nvl\Comments\Enums\CommentReportStatus::Open="open"
public const Nvl\Comments\Enums\CommentReportStatus Resolved=Nvl\Comments\Enums\CommentReportStatus::Resolved="resolved"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Enums\CommentStatus

```text
enum Nvl\Comments\Enums\CommentStatus implements BackedEnum, UnitEnum: string
public const Nvl\Comments\Enums\CommentStatus Approved=Nvl\Comments\Enums\CommentStatus::Approved="approved"
public const Nvl\Comments\Enums\CommentStatus Hidden=Nvl\Comments\Enums\CommentStatus::Hidden="hidden"
public const Nvl\Comments\Enums\CommentStatus Pending=Nvl\Comments\Enums\CommentStatus::Pending="pending"
public const Nvl\Comments\Enums\CommentStatus Rejected=Nvl\Comments\Enums\CommentStatus::Rejected="rejected"
public const Nvl\Comments\Enums\CommentStatus Spam=Nvl\Comments\Enums\CommentStatus::Spam="spam"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Enums\CommentVisibility

```text
enum Nvl\Comments\Enums\CommentVisibility implements BackedEnum, UnitEnum: string
public const Nvl\Comments\Enums\CommentVisibility Internal=Nvl\Comments\Enums\CommentVisibility::Internal="internal"
public const Nvl\Comments\Enums\CommentVisibility Private=Nvl\Comments\Enums\CommentVisibility::Private="private"
public const Nvl\Comments\Enums\CommentVisibility Public=Nvl\Comments\Enums\CommentVisibility::Public="public"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Enums\CommentsResponseCode

```text
enum Nvl\Comments\Enums\CommentsResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Comments\Enums\CommentsResponseCode BatchReadUnavailable=Nvl\Comments\Enums\CommentsResponseCode::BatchReadUnavailable="batch_read_unavailable"
public const Nvl\Comments\Enums\CommentsResponseCode CommentAttachmentDeliveryUnavailable=Nvl\Comments\Enums\CommentsResponseCode::CommentAttachmentDeliveryUnavailable="comment_attachment_delivery_unavailable"
public const Nvl\Comments\Enums\CommentsResponseCode CommentIdempotencyConflict=Nvl\Comments\Enums\CommentsResponseCode::CommentIdempotencyConflict="comment_idempotency_conflict"
public const Nvl\Comments\Enums\CommentsResponseCode CommentMutationBusy=Nvl\Comments\Enums\CommentsResponseCode::CommentMutationBusy="comment_mutation_busy"
public const Nvl\Comments\Enums\CommentsResponseCode CommentTargetNotFound=Nvl\Comments\Enums\CommentsResponseCode::CommentTargetNotFound="comment_target_not_found"
public const Nvl\Comments\Enums\CommentsResponseCode InvalidCommentLifecycle=Nvl\Comments\Enums\CommentsResponseCode::InvalidCommentLifecycle="invalid_comment_lifecycle"
public const Nvl\Comments\Enums\CommentsResponseCode InvalidCommentMutation=Nvl\Comments\Enums\CommentsResponseCode::InvalidCommentMutation="invalid_comment_mutation"
public const Nvl\Comments\Enums\CommentsResponseCode InvalidConfiguration=Nvl\Comments\Enums\CommentsResponseCode::InvalidConfiguration="invalid_configuration"
public const Nvl\Comments\Enums\CommentsResponseCode OperationFailed=Nvl\Comments\Enums\CommentsResponseCode::OperationFailed="operation_failed"
public const Nvl\Comments\Enums\CommentsResponseCode StaleComment=Nvl\Comments\Enums\CommentsResponseCode::StaleComment="stale_comment"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Comments\Events\CommentChanged

```text
final class Nvl\Comments\Events\CommentChanged implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $commentId, string $targetType, string $targetId, Nvl\Comments\Enums\CommentChangeOperation $operation, int $revision, Nvl\Comments\Services\CommentActor $actor, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly array $actor
@var array{type: ?string, id: ?string, system: bool}
public readonly string $commentId
public readonly Nvl\Comments\Enums\CommentChangeOperation $operation
public readonly int $revision
public readonly int $schemaVersion
public readonly string $targetId
public readonly string $targetType
```

## Nvl\Comments\Events\CommentMentionsChanged

```text
final class Nvl\Comments\Events\CommentMentionsChanged implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $commentId, string $targetType, string $targetId, int $revision, array $added, array $removed, int $schemaVersion=1): mixed
@param list<array{resourceAlias: string, resourceId: string, tokenId: string}> $added
@param list<array{resourceAlias: string, resourceId: string, tokenId: string}> $removed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly array $added
@param list<array{resourceAlias: string, resourceId: string, tokenId: string}> $added
public readonly string $commentId
public readonly array $removed
@param list<array{resourceAlias: string, resourceId: string, tokenId: string}> $removed
public readonly int $revision
public readonly int $schemaVersion
public readonly string $targetId
public readonly string $targetType
```

## Nvl\Comments\Events\CommentReactionChanged

```text
final class Nvl\Comments\Events\CommentReactionChanged implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $commentId, string $type, bool $active, Nvl\Comments\Services\CommentActor $actor, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly bool $active
public readonly array $actor
@var array{type: ?string, id: ?string, system: bool}
public readonly string $commentId
public readonly int $schemaVersion
public readonly string $type
```

## Nvl\Comments\Events\CommentReported

```text
final class Nvl\Comments\Events\CommentReported implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $commentId, string $reportId, Nvl\Comments\Services\CommentActor $actor, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly array $actor
@var array{type: ?string, id: ?string, system: bool}
public readonly string $commentId
public readonly string $reportId
public readonly int $schemaVersion
```

## Nvl\Comments\Exceptions\CommentAttachmentDeliveryUnavailableException

```text
final class Nvl\Comments\Exceptions\CommentAttachmentDeliveryUnavailableException extends Nvl\Comments\Exceptions\CommentsException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Exceptions\CommentBatchReadException

```text
final class Nvl\Comments\Exceptions\CommentBatchReadException extends Nvl\Comments\Exceptions\CommentsException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Exceptions\CommentIdempotencyConflictException

```text
final class Nvl\Comments\Exceptions\CommentIdempotencyConflictException extends Nvl\Comments\Exceptions\CommentsException implements Illuminate\Contracts\Debug\ShouldntReport
public static function forKey(): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Exceptions\CommentMutationBusyException

```text
final class Nvl\Comments\Exceptions\CommentMutationBusyException extends Nvl\Comments\Exceptions\CommentsException implements Illuminate\Contracts\Debug\ShouldntReport
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Exceptions\CommentMutationLockConfigurationException

```text
final class Nvl\Comments\Exceptions\CommentMutationLockConfigurationException extends Nvl\Comments\Exceptions\CommentsException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Exceptions\CommentTargetNotFoundException

```text
final class Nvl\Comments\Exceptions\CommentTargetNotFoundException extends Nvl\Comments\Exceptions\CommentsException implements Illuminate\Contracts\Debug\ShouldntReport
public static function forAlias(string $alias): self
public static function forIdentifier(string $alias, string $identifier): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Exceptions\CommentsException

```text
class Nvl\Comments\Exceptions\CommentsException extends RuntimeException implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Exceptions\InvalidCommentLifecycleException

```text
final class Nvl\Comments\Exceptions\InvalidCommentLifecycleException extends Nvl\Comments\Exceptions\CommentsException implements Illuminate\Contracts\Debug\ShouldntReport
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Exceptions\InvalidCommentMutationException

```text
final class Nvl\Comments\Exceptions\InvalidCommentMutationException extends InvalidArgumentException implements Illuminate\Contracts\Debug\ShouldntReport, Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Exceptions\StaleCommentException

```text
final class Nvl\Comments\Exceptions\StaleCommentException extends Nvl\Comments\Exceptions\CommentsException implements Illuminate\Contracts\Debug\ShouldntReport
public static function forComment(string $id): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Comments\Models\Comment

```text
class Nvl\Comments\Models\Comment extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function commentable(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function mentions(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<CommentMention, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Comments\Models\CommentMention, $this>
public function metadataValues(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<CommentMetadataValue, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Comments\Models\CommentMetadataValue, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\Comment>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function parent(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Comment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Comments\Models\Comment, $this>
public function reactions(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<CommentReaction, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Comments\Models\CommentReaction, $this>
public function refresh(): static
public function refreshForUpdate(): static
public function replies(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<Comment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Comments\Models\Comment, $this>
public function reports(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<CommentReport, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Comments\Models\CommentReport, $this>
public function resolveRouteBinding(mixed $value, mixed $field=null): ?Nvl\Comments\Models\Comment
@param mixed $value
@param (string | null) $field
public function resolveSoftDeletableRouteBinding(mixed $value, mixed $field=null): ?Nvl\Comments\Models\Comment
@param mixed $value
@param (string | null) $field
public function revisions(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<CommentRevision, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Comments\Models\CommentRevision, $this>
public function root(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Comment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Comments\Models\Comment, $this>
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function scopePubliclyVisible(Illuminate\Database\Eloquent\Builder $query): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\Comment> $query
@method MorphMany<MediaAssociation, $this> attachmentAssociations()
resolved @method Illuminate\Database\Eloquent\Relations\MorphMany<Nvl\Media\Models\MediaAssociation, $this> attachmentAssociations()
@method MorphMany<MediaAssociation, $this> mediaAssociations()
resolved @method Illuminate\Database\Eloquent\Relations\MorphMany<Nvl\Media\Models\MediaAssociation, $this> mediaAssociations()
@property (string | null) $actor_id
@property (string | null) $actor_identity_hash
@property (string | null) $actor_type
@property (string | null) $anonymization_reason
@property (Carbon | null) $anonymized_at
resolved @property (Illuminate\Support\Carbon | null) $anonymized_at
@property (string | null) $anonymized_by
@property (string | null) $anonymized_by_type
@property-read Collection<int, MediaAssociation> $attachmentAssociations
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Media\Models\MediaAssociation> $attachmentAssociations
@property string $body
@property-read (Model | null) $commentable
resolved @property-read (Illuminate\Database\Eloquent\Model | null) $commentable
@property string $commentable_id
@property string $commentable_identity_hash
@property string $commentable_type
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property (Carbon | null) $deleted_at
resolved @property (Illuminate\Support\Carbon | null) $deleted_at
@property (string | null) $deleted_by
@property (string | null) $deleted_by_type
@property int $depth
@property (array<string, mixed> | null) $document
@property (Carbon | null) $edited_at
resolved @property (Illuminate\Support\Carbon | null) $edited_at
@property CommentFormat $format
resolved @property Nvl\Comments\Enums\CommentFormat $format
@property string $id
@property (string | null) $idempotency_hash
@property (string | null) $idempotency_key
@property bool $is_pinned
@property (string | null) $locale
@property-read Collection<int, CommentMention> $mentions
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Comments\Models\CommentMention> $mentions
@property (array<string, mixed> | null) $metadata
@property-read Collection<int, CommentMetadataValue> $metadataValues
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Comments\Models\CommentMetadataValue> $metadataValues
@property (Carbon | null) $moderated_at
resolved @property (Illuminate\Support\Carbon | null) $moderated_at
@property (string | null) $moderated_by
@property (string | null) $moderated_by_type
@property (string | null) $moderation_reason
@property int $open_report_count
@property-read (Comment | null) $parent
resolved @property-read (Nvl\Comments\Models\Comment | null) $parent
@property (string | null) $parent_id
@property int $reaction_count
@property-read Collection<int, CommentReaction> $reactions
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Comments\Models\CommentReaction> $reactions
@property-read Collection<int, Comment> $replies
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Comments\Models\Comment> $replies
@property int $reply_count
@property int $report_count
@property-read Collection<int, CommentReport> $reports
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Comments\Models\CommentReport> $reports
@property (Carbon | null) $restored_at
resolved @property (Illuminate\Support\Carbon | null) $restored_at
@property (string | null) $restored_by
@property (string | null) $restored_by_type
@property int $revision
@property-read Collection<int, CommentRevision> $revisions
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Comments\Models\CommentRevision> $revisions
@property-read (Comment | null) $root
resolved @property-read (Nvl\Comments\Models\Comment | null) $root
@property (string | null) $root_id
@property CommentStatus $status
resolved @property Nvl\Comments\Enums\CommentStatus $status
@property string $status_hash
@property (list<string> | null) $tags
@property (string | null) $tenant_id
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
@property CommentVisibility $visibility
resolved @property Nvl\Comments\Enums\CommentVisibility $visibility
@property string $visibility_hash
```

## Nvl\Comments\Models\CommentMention

```text
final class Nvl\Comments\Models\CommentMention extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function comment(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Comment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Comments\Models\Comment, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\CommentMention>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property-read Comment $comment
resolved @property-read Nvl\Comments\Models\Comment $comment
@property string $comment_id
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property string $id
@property string $label_snapshot
@property int $position
@property string $resource_alias
@property string $resource_id
@property string $resource_identity_hash
@property string $token_id
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Comments\Models\CommentMetadataValue

```text
final class Nvl\Comments\Models\CommentMetadataValue extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function comment(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Comment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Comments\Models\Comment, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\CommentMetadataValue>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property-read Comment $comment
resolved @property-read Nvl\Comments\Models\Comment $comment
@property string $comment_id
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property string $field_name
@property string $id
@property string $schema_namespace
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
@property string $value_hash
@property string $value_type
```

## Nvl\Comments\Models\CommentReaction

```text
final class Nvl\Comments\Models\CommentReaction extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function comment(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Comment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Comments\Models\Comment, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\CommentReaction>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property string $actor_id
@property string $actor_identity_hash
@property string $actor_type
@property-read Comment $comment
resolved @property-read Nvl\Comments\Models\Comment $comment
@property string $comment_id
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property string $id
@property (string | null) $tenant_id
@property string $type
@property string $type_hash
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Comments\Models\CommentReport

```text
final class Nvl\Comments\Models\CommentReport extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function comment(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Comment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Comments\Models\Comment, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\CommentReport>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property-read Comment $comment
resolved @property-read Nvl\Comments\Models\Comment $comment
@property string $comment_id
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property (string | null) $details
@property string $id
@property string $reason
@property string $reporter_id
@property string $reporter_identity_hash
@property string $reporter_type
@property (string | null) $resolution
@property (Carbon | null) $reviewed_at
resolved @property (Illuminate\Support\Carbon | null) $reviewed_at
@property (string | null) $reviewed_by
@property (string | null) $reviewed_by_type
@property CommentReportStatus $status
resolved @property Nvl\Comments\Enums\CommentReportStatus $status
@property string $status_hash
@property Carbon $updated_at
resolved @property Illuminate\Support\Carbon $updated_at
```

## Nvl\Comments\Models\CommentRevision

```text
final class Nvl\Comments\Models\CommentRevision extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const null UPDATED_AT=null
public function comment(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Comment, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Comments\Models\Comment, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\CommentRevision>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property string $body
@property-read Comment $comment
resolved @property-read Nvl\Comments\Models\Comment $comment
@property string $comment_id
@property Carbon $created_at
resolved @property Illuminate\Support\Carbon $created_at
@property (array<string, mixed> | null) $document
@property (string | null) $edited_by
@property (string | null) $edited_by_type
@property CommentFormat $format
resolved @property Nvl\Comments\Enums\CommentFormat $format
@property string $id
@property (string | null) $locale
@property (array<string, mixed> | null) $metadata
@property int $revision
@property (list<string> | null) $tags
@property (string | null) $tenant_id
```

## Nvl\Comments\Services\CommentAttachments

```text
final readonly class Nvl\Comments\Services\CommentAttachments
public function __construct(Nvl\Comments\Services\CommentAccessService $access, Nvl\Comments\Services\CommentMutationLock $locks, Nvl\Comments\Services\CommentReadService $reads, Nvl\Comments\Services\CommentTargetLocator $targets, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Support\Integrations\OptionalIntegration $integrations): mixed
public function attach(Nvl\Comments\Models\Comment|string $comment, Nvl\Media\Models\Media|string $media, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): Nvl\Media\Models\MediaAssociation
public function detach(Nvl\Comments\Models\Comment|string $comment, string $associationId, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): bool
```

## Nvl\Comments\Services\CommentMentions

```text
final readonly class Nvl\Comments\Services\CommentMentions
public function __construct(Nvl\Comments\Services\CommentAccessService $access, Nvl\Comments\Services\CommentMentionProjectionFactory $projections, Nvl\Comments\Services\CommentMentionResourceRegistry $resources, Nvl\Comments\Services\CommentReadService $reads, Nvl\Comments\Services\CommentTargetLocator $targets): mixed
public function resolve(Nvl\Comments\Models\Comment|string $comment, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): array
@return list<array<string, mixed>>
public function suggest(Illuminate\Database\Eloquent\Model $target, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, string $resource, string $query, int $limit=10, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): array
@return list<array{id: string, label: ?string, fields: array<string, (scalar | null)>, url: ?string}>
```

## Nvl\Comments\Services\CommentModelRegistry

```text
final class Nvl\Comments\Services\CommentModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function commentClass(): string
@return class-string<Comment>
resolved @return class-string<Nvl\Comments\Models\Comment>
```

## Nvl\Comments\Services\CommentModeration

```text
final readonly class Nvl\Comments\Services\CommentModeration
public function __construct(Nvl\Comments\Services\CommentModerationWriter $writer, Nvl\Comments\Services\CommentReportWriter $reports, Nvl\Comments\Services\CommentReportResolver $resolutions): mixed
public function moderate(Nvl\Comments\Models\Comment|string $comment, array $attributes, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Comments\Models\Comment
@param array<string, mixed> $attributes
public function report(Nvl\Comments\Models\Comment|string $comment, array $attributes, Illuminate\Contracts\Auth\Authenticatable $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): Nvl\Comments\Models\CommentReport
@param array<string, mixed> $attributes
public function resolveReport(Nvl\Comments\Models\CommentReport|string $report, array $attributes, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Comments\Models\CommentReport
@param array<string, mixed> $attributes
```

## Nvl\Comments\Services\CommentReactions

```text
final readonly class Nvl\Comments\Services\CommentReactions
public function __construct(Nvl\Comments\Services\CommentAccessService $access, Nvl\Comments\Services\CommentMutationLock $mutationLock, Nvl\Comments\Services\CommentReadService $reads, Nvl\Comments\Services\CommentTargetLocator $targets, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Support\Events\DomainEventDispatcher $domainEvents): mixed
public function add(Nvl\Comments\Models\Comment|string $comment, string $type, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): Nvl\Comments\Models\CommentReaction
public function remove(Nvl\Comments\Models\Comment|string $comment, string $type, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): void
```

## Nvl\Comments\Services\CommentReader

```text
final readonly class Nvl\Comments\Services\CommentReader
public function __construct(Nvl\Comments\Services\CommentAccessService $access, Nvl\Comments\Services\CommentBatchReader $batch, Nvl\Comments\Services\CommentReadService $reads, Nvl\Comments\Services\CommentTargetLocator $targets, Nvl\Comments\Services\CommentMetadataRegistry $metadata, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Comments\Services\CommentMentionProjectionFactory $projections): mixed
public function forOwners(array $owners, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public, int $latestLimit=3): array
@param list<Model> $owners
resolved @param list<Illuminate\Database\Eloquent\Model> $owners
@return array<string, mixed>
public function latest(Illuminate\Database\Eloquent\Model $target, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): ?array
@return (array<string, mixed> | null)
public function paginate(Illuminate\Database\Eloquent\Model $target, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public, ?int $perPage=null, ?string $parentId=null): Illuminate\Pagination\LengthAwarePaginator
@return LengthAwarePaginator<int, array<string, mixed>>
resolved @return Illuminate\Pagination\LengthAwarePaginator<int, array<string, mixed>>
public function reports(Nvl\Comments\Models\Comment|string $comment, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?int $perPage=null): Illuminate\Pagination\LengthAwarePaginator
@return LengthAwarePaginator<int, array{id: string, reason: string, details: ?string, status: CommentReportStatus, resolution: ?string}>
resolved @return Illuminate\Pagination\LengthAwarePaginator<int, array{id: string, reason: string, details: ?string, status: Nvl\Comments\Enums\CommentReportStatus, resolution: ?string}>
public function revisions(Nvl\Comments\Models\Comment|string $comment, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public, ?int $perPage=null): Illuminate\Pagination\LengthAwarePaginator
@return LengthAwarePaginator<int, array{id: string, revision: int, body: string, format: CommentFormat, locale: ?string, tags: (list<string> | null), metadata: list<array{namespace: string, values: array<string, mixed>}>, document: (array<string, mixed> | null), mentions: list<array<string, mixed>>}>
resolved @return Illuminate\Pagination\LengthAwarePaginator<int, array{id: string, revision: int, body: string, format: Nvl\Comments\Enums\CommentFormat, locale: ?string, tags: (list<string> | null), metadata: list<array{namespace: string, values: array<string, mixed>}>, document: (array<string, mixed> | null), mentions: list<array<string, mixed>>}>
```

## Nvl\Comments\Services\CommentWriter

```text
final readonly class Nvl\Comments\Services\CommentWriter
public function __construct(Nvl\Comments\Services\CommentAccessService $access, Nvl\Comments\Services\CommentContentGuard $guard, Nvl\Comments\Services\CommentDeletionWriter $deletions, Nvl\Comments\Services\CommentUpdateWriter $updates, Nvl\Comments\Services\CommentRichUpdateWriter $richUpdates, Nvl\Comments\Services\CommentRestorationWriter $restorations, Nvl\Comments\Services\CommentRevisionRestorer $revisions, Nvl\Comments\Services\CommentAnonymizer $anonymizer, Nvl\Comments\Services\CommentDocumentNormalizer $documents, Nvl\Comments\Services\CommentIdempotencyDigest $idempotency, Nvl\Comments\Services\CommentLifecycleGuard $lifecycle, Nvl\Comments\Services\CommentMentionWriter $mentions, Nvl\Comments\Services\CommentMetadataIndexWriter $metadataIndex, Nvl\Comments\Services\CommentMutationLock $mutationLock, Nvl\Comments\Services\CommentReadService $reads, Nvl\Comments\Services\CommentTargetLocator $targets, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Support\Events\DomainEventDispatcher $domainEvents): mixed
public function anonymize(Nvl\Comments\Models\Comment|string $comment, array $attributes, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Management): Nvl\Comments\Models\Comment
@param array{expected_revision: int, reason: string} $attributes
public function create(Illuminate\Database\Eloquent\Model $target, array $data, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): Nvl\Comments\Models\Comment
@param array<string, mixed> $data
public function createRich(Illuminate\Database\Eloquent\Model $target, array $data, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): Nvl\Comments\Models\Comment
@param array<string, mixed> $data
public function delete(Nvl\Comments\Models\Comment|string $comment, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): bool
public function restore(Nvl\Comments\Models\Comment|string $comment, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): Nvl\Comments\Models\Comment
public function restoreRevision(Nvl\Comments\Models\Comment|string $comment, Nvl\Comments\Models\CommentRevision|string $revision, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): Nvl\Comments\Models\Comment
public function update(Nvl\Comments\Models\Comment|string $comment, array $attributes, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): Nvl\Comments\Models\Comment
@param array<string, mixed> $attributes
public function updateRich(Nvl\Comments\Models\Comment|string $comment, array $attributes, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public): Nvl\Comments\Models\Comment
@param array<string, mixed> $attributes
```

## Nvl\Comments\Services\ConfiguredCommentAuthorization

```text
final class Nvl\Comments\Services\ConfiguredCommentAuthorization implements Nvl\Comments\Contracts\CommentAuthorization, Nvl\Comments\Contracts\CommentBatchQueryScope, Nvl\Comments\Contracts\CommentQueryScope
public function allows(Nvl\Comments\Enums\CommentAbility $ability, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, ?Nvl\Comments\Models\Comment $comment=null, ?Illuminate\Database\Eloquent\Model $target=null, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public, array $context=[]): bool
@param array<string, mixed> $context
public function authorizeOwners(Nvl\Support\Owners\OwnerBatch $owners, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience): void
public function scopeBatchComments(Illuminate\Database\Eloquent\Builder $query, Nvl\Support\Owners\OwnerBatch $owners, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience): void
@param Builder<Comment> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\Comment> $query
public function scopeComments(Illuminate\Database\Eloquent\Builder $query, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Illuminate\Database\Eloquent\Model $target, Nvl\Comments\Enums\CommentAudience $audience, Nvl\Comments\Enums\CommentAbility $ability): void
@param Builder<Comment> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\Comment> $query
public function scopeHostComments(Illuminate\Database\Eloquent\Builder $query, Illuminate\Database\Eloquent\Model $ownerPrototype, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience): void
@param Builder<Comment> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Comments\Models\Comment> $query
```

## Nvl\Comments\Support\CommentIdentity

```text
final class Nvl\Comments\Support\CommentIdentity
public static function actor(?string $type, ?string $identifier): ?string
public static function pair(string $type, string $identifier): string
public static function value(string $domain, string|BackedEnum $value): string
```

## Nvl\Comments\Traits\InteractsWithComments

```text
trait Nvl\Comments\Traits\InteractsWithComments
@mixin Model
resolved @mixin Illuminate\Database\Eloquent\Model
public function scopeWithNvlVisibleCommentCount(Illuminate\Database\Eloquent\Builder $query, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor|Nvl\Support\Enums\PublicActor $actor, Nvl\Comments\Enums\CommentAudience $audience=\Nvl\Comments\Enums\CommentAudience::Public, ?Nvl\Comments\Contracts\CommentBatchQueryScope $policy=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Comments\Traits\InteractsWithComments> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Comments\Traits\InteractsWithComments>
```

## Nvl\Comments\ValueObjects\CommentMentionContext

```text
final readonly class Nvl\Comments\ValueObjects\CommentMentionContext
public function __construct(Illuminate\Database\Eloquent\Model $target, Nvl\Comments\Services\CommentActor $actor, Nvl\Comments\Enums\CommentAudience $audience): mixed
public readonly Nvl\Comments\Services\CommentActor $actor
public readonly Nvl\Comments\Enums\CommentAudience $audience
public readonly Illuminate\Database\Eloquent\Model $target
```
