# NVL Comments

`nvl/comments` provides threaded comments, revisions, reactions, reports, moderation, mentions, and optional Media attachments for Laravel 13 and PHP 8.4. It is a headless domain package. The host application owns routes, request validation, response resources, identity presentation, and frontend types.

## Install

```sh
composer require nvl/comments:^7.0
php artisan migrate
```

Laravel discovers `Nvl\Comments\Providers\CommentsServiceProvider`. Vendor migrations load by default. If the host publishes them, set `nvl-comments.migrations.enabled` to `false` before migrating so only one migration source runs. The package config and translations may be published with `nvl-comments-config` and `nvl-comments-translations`.

Register each comment target through `nvl-comments.targets` with a `CommentTargetResolver`. Bind `CommentAuthorization`, `CommentQueryScope`, and `CommentBatchQueryScope` to host policy implementations when the default public discussion policy is insufficient. Host policy receives the explicit actor on every call. `SystemActor::Background` identifies background work; it does not grant permission. Human actors must be persisted `Authenticatable` Eloquent models with canonical table, key, and connection. Anonymous creation uses `PublicActor::Anonymous` and requires `nvl-comments.anonymous.enabled=true`.

## Native services

Inject these services from the Laravel container. Inputs are native models, enums, scalars, and shaped arrays; methods require an explicit actor.

| Service | Operations |
| --- | --- |
| `CommentWriter` | `create`, `createRich`, `update`, `updateRich`, `delete`, `restore`, `restoreRevision`, `anonymize` |
| `CommentReader` | `paginate`, `forOwners`, `latest`, `revisions`, `reports` |
| `CommentReactions` | `add`, `remove` |
| `CommentModeration` | `moderate`, `report`, `resolveReport` |
| `CommentMentions` | `resolve`, `suggest` |
| `CommentAttachments` | `attach`, `detach`, when `nvl/media` is installed and enabled |

```php
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Services\CommentReader;
use Nvl\Comments\Services\CommentWriter;

$comment = app(CommentWriter::class)->create(
    $article,
    ['body' => 'A comment'],
    $user,
    CommentAudience::Public,
);

$page = app(CommentReader::class)->paginate($article, $user);
$summaries = app(CommentReader::class)->forOwners([$article], $user);
```

Reads return audience-safe shaped arrays or paginators of them. Batch owner reads return `order` and `values`, preserve requested owner order, and return an explicit empty result for an empty owner list. Raw Eloquent models returned by mutation services contain stored actor identity and belong only in trusted application code. Consumers should map their own API resources.

Writes preserve canonical target identity, ownership admission, revision checks, idempotency, and transactional lifecycle changes. Rich documents use bounded blocks and registered mention resources. Metadata accepts only registered fields and projects values by audience. A missing optional Media provider does not affect comment reads and writes; an explicitly requested attachment operation fails clearly.

Run `php artisan nvl:comments:doctor` after configuring the package. See [events](docs/events.md) for domain notifications and [contributing](CONTRIBUTING.md) for package development.

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.
