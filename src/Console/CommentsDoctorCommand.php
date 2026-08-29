<?php

declare(strict_types=1);

namespace Nvl\Comments\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Contracts\CommentActorResolver;
use Nvl\Comments\Contracts\CommentAuthorization;
use Nvl\Comments\Contracts\CommentAuthorPresenter;
use Nvl\Comments\Contracts\CommentQueryScope;
use Nvl\Comments\Contracts\CommentTargetResolver;
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Models\CommentRevision;
use Nvl\Comments\Services\CommentMentionResourceRegistry;
use Nvl\Comments\Services\CommentMetadataRegistry;
use Nvl\Comments\Services\CommentMutationLockStore;
use Nvl\Comments\Services\CommentTargetRegistry;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;
use Nvl\Comments\Support\CommentMutationLockConfiguration;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\Support\CommentsRouteConfiguration;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Throwable;

/**
 * Performs non-mutating Comments installation diagnostics.
 */
final class CommentsDoctorCommand extends Command
{
    /** @var array<string, list<string>> */
    private const array REQUIRED_COLUMNS = [
        CommentsTables::Comments => [
            'id',
            'commentable_type',
            'commentable_id',
            'commentable_identity_hash',
            'root_id',
            'parent_id',
            'depth',
            'actor_type',
            'actor_id',
            'actor_identity_hash',
            'idempotency_key',
            'idempotency_hash',
            'body',
            'format',
            'locale',
            'status',
            'status_hash',
            'visibility',
            'visibility_hash',
            'tags',
            'metadata',
            'revision',
            'reply_count',
            'reaction_count',
            'report_count',
            'open_report_count',
            'is_pinned',
            'edited_at',
            'moderated_by_type',
            'moderated_by',
            'moderation_reason',
            'moderated_at',
            'deleted_by_type',
            'deleted_by',
            'restored_at',
            'restored_by_type',
            'restored_by',
            'anonymized_at',
            'anonymized_by_type',
            'anonymized_by',
            'anonymization_reason',
            'created_at',
            'updated_at',
            'deleted_at',
        ],
        CommentsTables::Reactions => [
            'id',
            'comment_id',
            'actor_type',
            'actor_id',
            'actor_identity_hash',
            'type',
            'type_hash',
            'created_at',
            'updated_at',
        ],
        CommentsTables::Revisions => [
            'id',
            'comment_id',
            'revision',
            'body',
            'format',
            'locale',
            'tags',
            'metadata',
            'edited_by_type',
            'edited_by',
            'created_at',
        ],
        CommentsTables::Reports => [
            'id',
            'comment_id',
            'reporter_type',
            'reporter_id',
            'reporter_identity_hash',
            'reason',
            'details',
            'status',
            'status_hash',
            'reviewed_by_type',
            'reviewed_by',
            'resolution',
            'reviewed_at',
            'created_at',
            'updated_at',
        ],
        CommentsTables::MetadataValues => [
            'id',
            'comment_id',
            'schema_namespace',
            'field_name',
            'value_type',
            'value_hash',
            'created_at',
            'updated_at',
        ],
        CommentsTables::Mentions => [
            'id',
            'comment_id',
            'token_id',
            'resource_alias',
            'resource_id',
            'resource_identity_hash',
            'label_snapshot',
            'position',
            'created_at',
            'updated_at',
        ],
    ];

    /**
     * @var array<string, array<string, array{
     *     kind: string,
     *     nullable: bool,
     *     length?: positive-int,
     *     default?: bool|int|string|null
     * }>>
     */
    private const array REQUIRED_COLUMN_DEFINITIONS = [
        CommentsTables::Comments => [
            'id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'commentable_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => false,
                'default' => null,
            ],
            'commentable_id' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => false,
                'default' => null,
            ],
            'commentable_identity_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'root_id' => ['kind' => 'uuid', 'nullable' => true, 'default' => null],
            'parent_id' => ['kind' => 'uuid', 'nullable' => true, 'default' => null],
            'depth' => [
                'kind' => 'unsigned_small_integer',
                'nullable' => false,
                'default' => 0,
            ],
            'actor_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => true,
                'default' => null,
            ],
            'actor_id' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => true,
                'default' => null,
            ],
            'actor_identity_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => true,
                'default' => null,
            ],
            'idempotency_key' => [
                'kind' => 'uuid',
                'nullable' => true,
                'default' => null,
            ],
            'idempotency_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => true,
                'default' => null,
            ],
            'format' => [
                'kind' => 'string',
                'length' => 32,
                'nullable' => false,
                'default' => 'plain',
            ],
            'locale' => [
                'kind' => 'string',
                'length' => 35,
                'nullable' => true,
                'default' => null,
            ],
            'status' => [
                'kind' => 'string',
                'length' => 32,
                'nullable' => false,
                'default' => 'pending',
            ],
            'status_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'visibility' => [
                'kind' => 'string',
                'length' => 32,
                'nullable' => false,
                'default' => 'public',
            ],
            'visibility_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'revision' => [
                'kind' => 'unsigned_big_integer',
                'nullable' => false,
                'default' => 1,
            ],
            'reply_count' => [
                'kind' => 'unsigned_integer',
                'nullable' => false,
                'default' => 0,
            ],
            'reaction_count' => [
                'kind' => 'unsigned_integer',
                'nullable' => false,
                'default' => 0,
            ],
            'report_count' => [
                'kind' => 'unsigned_integer',
                'nullable' => false,
                'default' => 0,
            ],
            'open_report_count' => [
                'kind' => 'unsigned_integer',
                'nullable' => false,
                'default' => 0,
            ],
            'is_pinned' => [
                'kind' => 'boolean',
                'nullable' => false,
                'default' => false,
            ],
            'edited_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'moderated_by_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => true,
                'default' => null,
            ],
            'moderated_by' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => true,
                'default' => null,
            ],
            'moderated_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'deleted_by_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => true,
                'default' => null,
            ],
            'deleted_by' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => true,
                'default' => null,
            ],
            'restored_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'restored_by_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => true,
                'default' => null,
            ],
            'restored_by' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => true,
                'default' => null,
            ],
            'anonymized_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'anonymized_by_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => true,
                'default' => null,
            ],
            'anonymized_by' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => true,
                'default' => null,
            ],
            'created_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'updated_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'deleted_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
        ],
        CommentsTables::Reactions => [
            'id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'comment_id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'actor_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => false,
                'default' => null,
            ],
            'actor_id' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => false,
                'default' => null,
            ],
            'actor_identity_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'type' => [
                'kind' => 'string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'type_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'created_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'updated_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
        ],
        CommentsTables::Revisions => [
            'id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'comment_id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'revision' => [
                'kind' => 'unsigned_big_integer',
                'nullable' => false,
                'default' => null,
            ],
            'format' => [
                'kind' => 'string',
                'length' => 32,
                'nullable' => false,
                'default' => null,
            ],
            'locale' => [
                'kind' => 'string',
                'length' => 35,
                'nullable' => true,
                'default' => null,
            ],
            'edited_by_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => true,
                'default' => null,
            ],
            'edited_by' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => true,
                'default' => null,
            ],
            'created_at' => [
                'kind' => 'timestamp',
                'nullable' => false,
                'default' => 'current_timestamp',
            ],
        ],
        CommentsTables::Reports => [
            'id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'comment_id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'reporter_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => false,
                'default' => null,
            ],
            'reporter_id' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => false,
                'default' => null,
            ],
            'reporter_identity_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'reason' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => false,
                'default' => null,
            ],
            'status' => [
                'kind' => 'string',
                'length' => 32,
                'nullable' => false,
                'default' => 'open',
            ],
            'status_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'reviewed_by_type' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => true,
                'default' => null,
            ],
            'reviewed_by' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => true,
                'default' => null,
            ],
            'reviewed_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'created_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'updated_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
        ],
        CommentsTables::MetadataValues => [
            'id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'comment_id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'schema_namespace' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => false,
                'default' => null,
            ],
            'field_name' => [
                'kind' => 'string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'value_type' => [
                'kind' => 'string',
                'length' => 16,
                'nullable' => false,
                'default' => null,
            ],
            'value_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'created_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'updated_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
        ],
        CommentsTables::Mentions => [
            'id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'comment_id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'token_id' => ['kind' => 'uuid', 'nullable' => false, 'default' => null],
            'resource_alias' => [
                'kind' => 'string',
                'length' => 100,
                'nullable' => false,
                'default' => null,
            ],
            'resource_id' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => false,
                'default' => null,
            ],
            'resource_identity_hash' => [
                'kind' => 'fixed_string',
                'length' => 64,
                'nullable' => false,
                'default' => null,
            ],
            'label_snapshot' => [
                'kind' => 'string',
                'length' => 255,
                'nullable' => false,
                'default' => null,
            ],
            'position' => [
                'kind' => 'unsigned_small_integer',
                'nullable' => false,
                'default' => null,
            ],
            'created_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
            'updated_at' => [
                'kind' => 'timestamp',
                'nullable' => true,
                'default' => null,
            ],
        ],
    ];

    /**
     * @var array<string, array<string, array{
     *     columns: list<string>,
     *     unique?: bool,
     *     primary?: bool
     * }>>
     */
    private const array REQUIRED_INDEXES = [
        CommentsTables::Comments => [
            'primary' => [
                'columns' => ['id'],
                'primary' => true,
            ],
            'idempotency_unique' => [
                'columns' => ['idempotency_key'],
                'unique' => true,
            ],
            'target_visibility' => [
                'columns' => [
                    'commentable_identity_hash',
                    'status_hash',
                    'visibility_hash',
                    'is_pinned',
                    'created_at',
                    'id',
                ],
            ],
            'thread_order' => [
                'columns' => [
                    'commentable_identity_hash',
                    'root_id',
                    'status_hash',
                    'visibility_hash',
                    'is_pinned',
                    'created_at',
                    'id',
                ],
            ],
            'parent_order' => [
                'columns' => ['parent_id', 'is_pinned', 'created_at', 'id'],
            ],
            'root_depth' => [
                'columns' => ['root_id', 'depth', 'created_at'],
            ],
            'actor' => [
                'columns' => ['actor_identity_hash', 'created_at'],
            ],
            'moderation' => [
                'columns' => [
                    'commentable_identity_hash',
                    'status_hash',
                    'open_report_count',
                    'report_count',
                    'created_at',
                    'id',
                ],
            ],
            'lifecycle' => [
                'columns' => [
                    'commentable_identity_hash',
                    'anonymized_at',
                    'deleted_at',
                    'id',
                ],
            ],
        ],
        CommentsTables::Reactions => [
            'primary' => [
                'columns' => ['id'],
                'primary' => true,
            ],
            'actor_type_unique' => [
                'columns' => ['comment_id', 'actor_identity_hash', 'type_hash'],
                'unique' => true,
            ],
            'type' => [
                'columns' => ['comment_id', 'type_hash'],
            ],
            'actor' => [
                'columns' => ['actor_identity_hash'],
            ],
        ],
        CommentsTables::Revisions => [
            'primary' => [
                'columns' => ['id'],
                'primary' => true,
            ],
            'number_unique' => [
                'columns' => ['comment_id', 'revision'],
                'unique' => true,
            ],
            'created' => [
                'columns' => ['comment_id', 'created_at'],
            ],
        ],
        CommentsTables::Reports => [
            'primary' => [
                'columns' => ['id'],
                'primary' => true,
            ],
            'reporter_unique' => [
                'columns' => ['comment_id', 'reporter_identity_hash'],
                'unique' => true,
            ],
            'status' => [
                'columns' => ['status_hash', 'created_at', 'id'],
            ],
            'comment' => [
                'columns' => ['comment_id', 'status_hash', 'created_at', 'id'],
            ],
        ],
        CommentsTables::MetadataValues => [
            'primary' => [
                'columns' => ['id'],
                'primary' => true,
            ],
            'owner_unique' => [
                'columns' => ['comment_id', 'schema_namespace', 'field_name'],
                'unique' => true,
            ],
            'lookup' => [
                'columns' => ['schema_namespace', 'field_name', 'value_hash'],
            ],
        ],
        CommentsTables::Mentions => [
            'primary' => [
                'columns' => ['id'],
                'primary' => true,
            ],
            'token_unique' => [
                'columns' => ['comment_id', 'token_id'],
                'unique' => true,
            ],
            'resource' => [
                'columns' => ['resource_alias', 'resource_identity_hash'],
            ],
            'position' => [
                'columns' => ['comment_id', 'position'],
            ],
        ],
    ];

    /** @var array<string, array<string, list<string>>> */
    private const array REQUIRED_FOREIGN_KEYS = [
        CommentsTables::Comments => [
            'parent' => ['parent_id'],
        ],
        CommentsTables::Reactions => [
            'comment' => ['comment_id'],
        ],
        CommentsTables::Revisions => [
            'comment' => ['comment_id'],
        ],
        CommentsTables::Reports => [
            'comment' => ['comment_id'],
        ],
        CommentsTables::MetadataValues => [
            'comment' => ['comment_id'],
        ],
        CommentsTables::Mentions => [
            'comment' => ['comment_id'],
        ],
    ];

    /** @var list<string> */
    private const array PUBLIC_ROUTE_NAMES = [
        'index',
        'store',
        'show',
        'update',
        'destroy',
        'reaction',
        'reports.store',
        'attachments.index',
        'attachments.store',
        'attachments.destroy',
    ];

    /** @var list<string> */
    private const array MEMBER_ROUTE_NAMES = [
        'index',
        'store',
        'rich.store',
        'mentions.suggestions',
        'show',
        'update',
        'rich.update',
        'destroy',
        'restore',
        'reaction',
        'reports.store',
        'attachments.index',
        'attachments.store',
        'attachments.destroy',
        'revisions.index',
        'revisions.restore',
    ];

    /** @var list<string> */
    private const array MANAGEMENT_ROUTE_NAMES = [
        'index',
        'rich.store',
        'mentions.suggestions',
        'rich.update',
        'target_reports.index',
        'moderate',
        'restore',
        'anonymize',
        'attachments.index',
        'attachments.destroy',
        'revisions.index',
        'revisions.restore',
        'reports.index',
        'reports.resolve',
    ];

    /** @var list<string> */
    private const array ATTACHMENT_ROUTE_NAMES = [
        'asset',
        'thumbnail',
    ];

    /** @var string */
    protected $signature = 'nvl:comments:doctor {--strict} {--format=text}';

    /** @var string */
    protected $description = 'Inspect the NVL Comments installation without changing state';

    /**
     * Inspect every required Comments installation boundary and render the report.
     */
    public function handle(
        CommentTargetRegistry $targets,
        CommentMutationLockStore $mutationLockStore,
        CommentMetadataRegistry $metadata,
        CommentMentionResourceRegistry $mentionResources,
        Container $container,
    ): int {
        try {
            $connectionName = CommentsConfiguration::connection();
            $schema = Schema::connection($connectionName);
            $driver = strtolower(DB::connection($connectionName)->getDriverName());
        } catch (Throwable) {
            $schema = null;
            $driver = '';
        }

        $checks = [];
        $requiredChecks = [];
        $tableIndexes = [];
        $tableForeignKeys = [];
        /**
         * @var array<string, array<string, array{
         *     name: string,
         *     type: string,
         *     type_name: string,
         *     nullable: bool,
         *     default: mixed
         * }>> $tableColumns
         */
        $tableColumns = [];
        $configurationValuesReady = $this->configurationValuesReady();
        $checks['configuration.values'] = $configurationValuesReady;
        $requiredChecks[] = $configurationValuesReady;
        $richTextBoundsReady = $this->richTextBoundsReady();
        $checks['rich_text.bounds_ready'] = $richTextBoundsReady;
        $requiredChecks[] = $richTextBoundsReady;
        $migrationDuplicates = config('comments.migrations.enabled') === true
            ? $this->publishedMigrationDuplicates(dirname(__DIR__, 2).'/database/migrations')
            : [];
        $migrationOwnershipReady = $migrationDuplicates === [];
        $checks['migrations.ownership'] = [
            'severity' => 'warning',
            'passed' => $migrationOwnershipReady,
            'message' => $migrationOwnershipReady
                ? 'Automatic vendor migration loading does not overlap a published host copy.'
                : sprintf(
                    'Automatic vendor migration loading overlaps published host migration(s): %s. Disable comments.migrations.enabled before running host-owned copies.',
                    implode(', ', $migrationDuplicates),
                ),
        ];

        foreach (self::REQUIRED_COLUMNS as $key => $requiredColumns) {
            try {
                $table = CommentsConfiguration::table($key);
                $tableReady = $schema instanceof SchemaBuilder && $schema->hasTable($table);
                $columnMetadata = $tableReady ? $schema->getColumns($table) : [];
                $tableColumns[$key] = [];

                foreach ($columnMetadata as $column) {
                    $tableColumns[$key][strtolower($column['name'])] = $column;
                }

                $columns = array_keys($tableColumns[$key]);
                $tableIndexes[$key] = $tableReady ? $schema->getIndexes($table) : [];
                $tableForeignKeys[$key] = $tableReady ? $schema->getForeignKeys($table) : [];
            } catch (Throwable) {
                $tableReady = false;
                $columns = [];
                $tableColumns[$key] = [];
                $tableIndexes[$key] = [];
                $tableForeignKeys[$key] = [];
            }

            $checks["table.{$key}"] = $tableReady;
            $requiredChecks[] = $tableReady;

            foreach ($requiredColumns as $column) {
                $columnReady = $tableReady && in_array(strtolower($column), $columns, true);
                $checks["column.{$key}.{$column}"] = $columnReady;
                $requiredChecks[] = $columnReady;
            }
        }

        foreach (self::REQUIRED_COLUMN_DEFINITIONS as $key => $definitions) {
            foreach ($definitions as $column => $definition) {
                $columnMetadata = $tableColumns[$key][strtolower($column)] ?? null;
                $definitionReady = $columnMetadata !== null
                    && $this->columnDefinitionReady($columnMetadata, $definition, $driver);
                $checks["column_definition.{$key}.{$column}"] = $definitionReady;
                $requiredChecks[] = $definitionReady;
            }
        }

        foreach (self::REQUIRED_INDEXES as $key => $requiredIndexes) {
            foreach ($requiredIndexes as $name => $definition) {
                $indexReady = $this->indexReady(
                    $tableIndexes[$key],
                    $definition['columns'],
                    $definition['unique'] ?? false,
                    $definition['primary'] ?? false,
                );
                $checks["index.{$key}.{$name}"] = $indexReady;
                $requiredChecks[] = $indexReady;
            }
        }

        try {
            $commentsTable = CommentsConfiguration::table(CommentsTables::Comments);
        } catch (Throwable) {
            $commentsTable = '';
        }

        foreach (self::REQUIRED_FOREIGN_KEYS as $key => $requiredForeignKeys) {
            foreach ($requiredForeignKeys as $name => $columns) {
                $foreignKeyReady = $this->foreignKeyReady(
                    $tableForeignKeys[$key],
                    $columns,
                    $commentsTable,
                );
                $checks["foreign_key.{$key}.{$name}"] = $foreignKeyReady;
                $requiredChecks[] = $foreignKeyReady;
            }
        }

        $metadataDigestReady = $metadata->hasStableDigestKey();
        [$metadataStrictCompatible, $metadataStrictIncompatibleRecords] =
            $this->strictMetadataCompatibility(
                $metadata,
                ($checks['column.comments.metadata'] ?? false) === true,
                ($checks['column.comment_revisions.metadata'] ?? false) === true,
            );
        $checks['metadata.schemas_ready'] = true;
        $checks['metadata.digest_key_ready'] = $metadataDigestReady;
        $checks['metadata.strict_compatible'] = $metadataStrictCompatible;
        $checks['metadata.strict_incompatible_records'] = $metadataStrictIncompatibleRecords;
        $requiredChecks[] = $metadataDigestReady;
        $requiredChecks[] = $metadataStrictCompatible;

        [$mentionBoundsReady, $mentionResourcesReady, $mentionDiagnostics] =
            $this->mentionReadiness($mentionResources);
        $checks['mentions.bounds_ready'] = $mentionBoundsReady;
        $checks['mentions.resources_ready'] = $mentionResourcesReady;
        $checks['mentions.aliases'] = $mentionDiagnostics['aliases'];
        $checks['mentions.registered'] = $mentionDiagnostics['registered'];
        $checks['mentions.aliases_truncated'] = $mentionDiagnostics['truncated'];
        $requiredChecks[] = $mentionBoundsReady;
        $requiredChecks[] = $mentionResourcesReady;

        $checks['targets'] = $targets->aliases();
        $targetResolversReady = $this->targetResolversReady($targets, $container);
        $checks['targets.ready'] = $targetResolversReady;
        $requiredChecks[] = $targetResolversReady;
        $publicRoutesEnabled = config('comments.routes.public.enabled', false) === true;
        $memberRoutesEnabled = config('comments.routes.member.enabled', false) === true;
        $managementRoutesEnabled = config('comments.routes.management.enabled', false) === true;
        $checks['public_routes'] = $publicRoutesEnabled;
        $checks['member_routes'] = $memberRoutesEnabled;
        $checks['management_routes'] = $managementRoutesEnabled;
        $checks['anonymous'] = config('comments.anonymous.enabled', false) === true;
        $attachmentsEnabled = config('comments.attachments.enabled', true) === true;
        $checks['attachments'] = $attachmentsEnabled;

        if ($attachmentsEnabled) {
            [$connectionReady, $tablesReady] = $this->attachmentReadiness();
            $attachmentRoutesReady = $this->attachmentRoutesReady();
            $checks['attachments.connection_ready'] = $connectionReady;
            $checks['attachments.tables_ready'] = $tablesReady;
            $checks['routes.attachments_ready'] = $attachmentRoutesReady;
            $requiredChecks[] = $connectionReady;
            $requiredChecks[] = $tablesReady;
            $requiredChecks[] = $attachmentRoutesReady;
        } else {
            $disabledStateReady = $this->disabledAttachmentStateReady();
            $checks['attachments.disabled_state_ready'] = $disabledStateReady;
            $requiredChecks[] = $disabledStateReady;
        }

        [$mutationLockConfigurationReady, $mutationLockReady] = $this->mutationLockReadiness(
            $mutationLockStore,
        );
        $checks['mutation_lock.configuration_ready'] = $mutationLockConfigurationReady;
        $checks['mutation_lock.ready'] = $mutationLockReady;
        $requiredChecks[] = $mutationLockConfigurationReady;
        $requiredChecks[] = $mutationLockReady;

        $authorization = $this->authorization($container);
        $queryScope = $this->queryScope($container);
        $actorResolverReady = $this->contractReady($container, CommentActorResolver::class);
        $authorPresenterReady = $this->contractReady(
            $container,
            CommentAuthorPresenter::class,
        );
        $queryScopeReady = $this->contractReady($container, CommentQueryScope::class);
        $checks['actor_resolver.ready'] = $actorResolverReady;
        $checks['author_presenter.ready'] = $authorPresenterReady;
        $checks['query_scope.ready'] = $queryScopeReady;
        $requiredChecks[] = $actorResolverReady;
        $requiredChecks[] = $authorPresenterReady;
        $requiredChecks[] = $queryScopeReady;

        if ($publicRoutesEnabled) {
            $routesReady = $this->routesReady(
                'public',
                $this->audienceRouteNames(self::PUBLIC_ROUTE_NAMES, $attachmentsEnabled),
                $targets,
            );
            $throttleReady = $this->throttledMiddlewareReady('public');
            $policyReady = $authorization instanceof CommentAuthorization;
            $checks['routes.public_ready'] = $routesReady;
            $checks['routes.public_throttled'] = $throttleReady;
            $checks['policy.public_ready'] = $policyReady;
            $requiredChecks[] = $routesReady;
            $requiredChecks[] = $throttleReady;
            $requiredChecks[] = $policyReady;
        }

        if ($memberRoutesEnabled) {
            $routesReady = $this->routesReady(
                'member',
                $this->audienceRouteNames(self::MEMBER_ROUTE_NAMES, $attachmentsEnabled),
                $targets,
            );
            $authenticatedReady = $this->authenticatedMiddlewareReady('member');
            $throttleReady = $this->throttledMiddlewareReady('member');
            $policyReady = $authorization instanceof CommentAuthorization;
            $checks['routes.member_ready'] = $routesReady;
            $checks['routes.member_authenticated'] = $authenticatedReady;
            $checks['routes.member_throttled'] = $throttleReady;
            $checks['policy.member_ready'] = $policyReady;
            $requiredChecks[] = $routesReady;
            $requiredChecks[] = $authenticatedReady;
            $requiredChecks[] = $throttleReady;
            $requiredChecks[] = $policyReady;
        }

        if ($managementRoutesEnabled) {
            $routesReady = $this->routesReady(
                'management',
                $this->audienceRouteNames(self::MANAGEMENT_ROUTE_NAMES, $attachmentsEnabled),
                $targets,
            );
            $authenticatedReady = $this->authenticatedMiddlewareReady('management');
            $throttleReady = $this->throttledMiddlewareReady('management');
            $policyReady = $authorization instanceof CommentAuthorization
                && ! $authorization instanceof ConfiguredCommentAuthorization;
            $managementScopeReady = $queryScope instanceof CommentQueryScope
                && ! $queryScope instanceof ConfiguredCommentAuthorization;
            $checks['routes.management_ready'] = $routesReady;
            $checks['routes.management_authenticated'] = $authenticatedReady;
            $checks['routes.management_throttled'] = $throttleReady;
            $checks['policy.management_ready'] = $policyReady;
            $checks['query_scope.management_ready'] = $managementScopeReady;
            $requiredChecks[] = $routesReady;
            $requiredChecks[] = $authenticatedReady;
            $requiredChecks[] = $throttleReady;
            $requiredChecks[] = $policyReady;
            $requiredChecks[] = $managementScopeReady;
        }

        $healthy = ! in_array(false, $requiredChecks, true);
        $checks['healthy'] = $healthy;

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $check => $value) {
                $this->line(sprintf(
                    '%-32s %s',
                    $check,
                    json_encode($value, JSON_THROW_ON_ERROR),
                ));
            }
        }

        $strictFailure = (bool) $this->option('strict')
            && (! $healthy || ! $migrationOwnershipReady);

        return $strictFailure ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Find host migrations whose timestamp-independent names match package migrations.
     *
     * @return list<string>
     */
    private function publishedMigrationDuplicates(string $packagePath): array
    {
        $packageMigrations = glob($packagePath.'/*.php') ?: [];
        $hostMigrations = glob(database_path('migrations/*.php')) ?: [];
        $packageNames = array_map($this->migrationName(...), $packageMigrations);
        $duplicates = [];

        foreach ($hostMigrations as $migration) {
            $name = $this->migrationName($migration);

            if (in_array($name, $packageNames, true)) {
                $duplicates[] = $name;
            }
        }

        sort($duplicates);

        return array_values(array_unique($duplicates));
    }

    /**
     * Remove Laravel's timestamp prefix from a migration filename.
     */
    private function migrationName(string $path): string
    {
        return (string) preg_replace(
            '/^\d{4}_\d{2}_\d{2}_\d{6}_/',
            '',
            pathinfo($path, PATHINFO_FILENAME),
        );
    }

    /**
     * Confirm configured target resolvers are registered, constructible, and self-consistent.
     */
    private function targetResolversReady(
        CommentTargetRegistry $targets,
        Container $container,
    ): bool {
        $configured = config('comments.targets', []);

        if (! is_array($configured)) {
            return false;
        }

        $registeredAliases = $targets->aliases();

        foreach ($configured as $alias => $resolverClass) {
            if (! is_string($alias)
                || ! is_string($resolverClass)
                || ! is_a($resolverClass, CommentTargetResolver::class, true)
                || ! in_array($alias, $registeredAliases, true)) {
                return false;
            }

            try {
                $resolver = $container->make($resolverClass);
            } catch (Throwable) {
                return false;
            }

            if (! $resolver instanceof CommentTargetResolver || $resolver->alias() !== $alias) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check that Comments and both Media attachment tables share one usable connection.
     *
     * @return array{bool, bool}
     */
    private function attachmentReadiness(): array
    {
        try {
            $comment = new Comment;
            $media = new Media;
            $association = new MediaAssociation;
            $commentConnection = DB::connection($comment->getConnectionName())->getName();
            $mediaConnection = DB::connection($media->getConnectionName())->getName();
            $associationConnection = DB::connection($association->getConnectionName())->getName();
            $connectionReady = $commentConnection === $mediaConnection
                && $commentConnection === $associationConnection;
            $tablesReady = Schema::connection($media->getConnectionName())
                ->hasTable($media->getTable())
                && Schema::connection($association->getConnectionName())
                    ->hasTable($association->getTable());

            return [$connectionReady, $tablesReady];
        } catch (Throwable) {
            return [false, false];
        }
    }

    /**
     * Permit an absent Media schema only when no historical comment attachment state remains.
     */
    private function disabledAttachmentStateReady(): bool
    {
        try {
            $association = new MediaAssociation;
            $schema = Schema::connection($association->getConnectionName());

            if (! $schema->hasTable($association->getTable())) {
                return true;
            }

            $hasHistoricalState = MediaAssociation::query()
                ->where('associable_type', (new Comment)->getMorphClass())
                ->where('collection', 'attachments')
                ->exists();

            if (! $hasHistoricalState) {
                return true;
            }

            [$connectionReady, $tablesReady] = $this->attachmentReadiness();

            return $connectionReady && $tablesReady;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Confirm mutation locking has exact settings and one safe atomic-lock domain.
     *
     * @return array{bool, bool}
     */
    private function mutationLockReadiness(
        CommentMutationLockStore $store,
    ): array {
        try {
            $settings = CommentMutationLockConfiguration::settings();
        } catch (Throwable) {
            return [false, false];
        }

        if (! $settings['enabled']) {
            return [true, false];
        }

        try {
            $store->provider(
                $settings['store'],
                $settings['allow_local_store'],
            );
        } catch (Throwable) {
            return [true, false];
        }

        return [true, true];
    }

    /**
     * Validate security-sensitive package values without coercing strings or fallbacks.
     */
    private function configurationValuesReady(): bool
    {
        if (! $this->richTextBoundsReady()) {
            return false;
        }

        $booleanKeys = [
            'comments.migrations.enabled',
            'comments.routes.public.enabled',
            'comments.routes.member.enabled',
            'comments.routes.management.enabled',
            'comments.mentions.enabled',
            'comments.routes.attachments.enabled',
            'comments.moderation.allow_author_delete',
            'comments.moderation.allow_author_restore',
            'comments.anonymous.enabled',
            'comments.attachments.enabled',
            'comments.attachments.allow_public_media',
        ];

        foreach ($booleanKeys as $key) {
            if (! is_bool(config($key))) {
                return false;
            }
        }

        $positiveIntegerKeys = [
            'comments.threading.maximum_depth',
            'comments.threading.maximum_replies_per_page',
            'comments.content.maximum_bytes',
            'comments.content.maximum_tags',
            'comments.metadata.maximum_bytes',
            'comments.metadata.maximum_registered_fields',
            'comments.attachments.maximum_per_comment',
            'comments.attachments.maximum_file_bytes',
            'comments.attachments.signed_url_lifetime',
            'comments.transactions.attempts',
            'comments.reconciliation.chunk_size',
            'comments.pagination.default',
            'comments.pagination.maximum',
        ];

        foreach ($positiveIntegerKeys as $key) {
            $value = config($key);

            if (! is_int($value) || $value < 1) {
                return false;
            }
        }

        $publicMaxAge = config('comments.cache.public_max_age');
        $connection = config('comments.connection');
        $metadataStrict = config('comments.metadata.strict');
        $metadataSchemas = config('comments.metadata.schemas');
        $metadataMaximumBytes = config('comments.metadata.maximum_bytes');
        $metadataMaximumFields = config('comments.metadata.maximum_registered_fields');

        if (! is_int($publicMaxAge)
            || $publicMaxAge < 0
            || ! is_bool($metadataStrict)
            || ! is_array($metadataSchemas)
            || ! array_is_list($metadataSchemas)
            || ! is_int($metadataMaximumBytes)
            || $metadataMaximumBytes > 65_536
            || ! is_int($metadataMaximumFields)
            || $metadataMaximumFields > 100
            || ($connection !== null
                && (! is_string($connection) || trim($connection) === ''))) {
            return false;
        }

        if (config('comments.migrations.enabled') === true
            && ($connection !== null || config('comments.tables') !== [
                CommentsTables::Comments => CommentsTables::Comments,
                CommentsTables::Reactions => CommentsTables::Reactions,
                CommentsTables::Revisions => CommentsTables::Revisions,
                CommentsTables::Reports => CommentsTables::Reports,
            ])) {
            return false;
        }

        try {
            foreach (['public', 'member', 'management', 'attachments'] as $group) {
                CommentsRouteConfiguration::path($group);
                CommentsRouteConfiguration::name($group);
                CommentsRouteConfiguration::middleware($group);
            }

            foreach (array_keys(self::REQUIRED_COLUMNS) as $table) {
                CommentsConfiguration::table($table);
            }
        } catch (Throwable) {
            return false;
        }

        $formats = config('comments.content.allowed_formats');
        $reactions = config('comments.reactions.allowed');
        $actionableStatuses = config('comments.moderation.actionable_statuses');
        $validFormats = array_column(CommentFormat::cases(), 'value');
        $validStatuses = array_column(CommentStatus::cases(), 'value');

        if (! $this->enumListReady($formats, $validFormats)
            || ! $this->stringListReady($reactions, 64, allowEmpty: true)
            || ! $this->enumListReady($actionableStatuses, $validStatuses)) {
            return false;
        }

        foreach (['new_status', 'edited_status', 'restored_status'] as $key) {
            $status = config("comments.moderation.{$key}");

            if (! is_string($status) || ! in_array($status, $validStatuses, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate every rich-document limit against its runtime hard cap.
     */
    private function richTextBoundsReady(): bool
    {
        foreach ([
            'comments.rich_text.maximum_bytes' => 131_072,
            'comments.rich_text.maximum_blocks' => 250,
            'comments.rich_text.maximum_nodes' => 1_000,
        ] as $key => $hardMaximum) {
            $value = config($key);

            if (! is_int($value) || $value < 1 || $value > $hardMaximum) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate hard mention caps and every server-owned resource definition.
     *
     * @return array{bool, bool, array{aliases: list<string>, ready: bool, registered: int, truncated: bool}}
     */
    private function mentionReadiness(CommentMentionResourceRegistry $resources): array
    {
        $maximumMentions = config('comments.mentions.maximum_per_comment');
        $maximumAliases = config('comments.mentions.maximum_resource_types_per_comment');
        $suggestionLimit = config('comments.mentions.suggestion_limit');
        $maximumSuggestionLimit = config('comments.mentions.maximum_suggestion_limit');
        $maximumQueryLength = config('comments.mentions.maximum_query_length');
        $maximumBatchSize = config('comments.mentions.maximum_batch_size');
        $boundsReady = is_int($maximumMentions)
            && $maximumMentions >= 1
            && $maximumMentions <= 100
            && is_int($maximumAliases)
            && $maximumAliases >= 1
            && $maximumAliases <= 20
            && is_int($suggestionLimit)
            && $suggestionLimit >= 1
            && is_int($maximumSuggestionLimit)
            && $maximumSuggestionLimit >= 1
            && $maximumSuggestionLimit <= 20
            && $suggestionLimit <= $maximumSuggestionLimit
            && is_int($maximumQueryLength)
            && $maximumQueryLength >= 1
            && $maximumQueryLength <= 160
            && is_int($maximumBatchSize)
            && $maximumBatchSize >= 1
            && $maximumBatchSize <= 100;

        $diagnostics = $resources->diagnostics();

        return [$boundsReady, $diagnostics['ready'], $diagnostics];
    }

    /**
     * Determine whether strict mode can accept every existing metadata key.
     *
     * @return array{bool, int}
     */
    private function strictMetadataCompatibility(
        CommentMetadataRegistry $metadata,
        bool $commentsTableReady,
        bool $revisionsTableReady,
    ): array {
        if (! $commentsTableReady || ! $revisionsTableReady) {
            return [false, 0];
        }

        $incompatibleRecords = 0;

        Comment::query()
            ->withTrashed()
            ->select(['id', 'metadata'])
            ->chunkById(500, function ($comments) use (
                $metadata,
                &$incompatibleRecords,
            ): void {
                foreach ($comments as $comment) {
                    foreach (array_keys($comment->metadata ?? []) as $storageKey) {
                        if (! $metadata->ownsStorageKey($storageKey)) {
                            $incompatibleRecords++;

                            break;
                        }
                    }
                }
            });

        CommentRevision::query()
            ->select(['id', 'metadata'])
            ->chunkById(500, function ($revisions) use (
                $metadata,
                &$incompatibleRecords,
            ): void {
                foreach ($revisions as $revision) {
                    foreach (array_keys($revision->metadata ?? []) as $storageKey) {
                        if (! $metadata->ownsStorageKey($storageKey)) {
                            $incompatibleRecords++;

                            break;
                        }
                    }
                }
            });

        return [$incompatibleRecords === 0, $incompatibleRecords];
    }

    /**
     * Remove HTTP attachment routes from an audience contract when the feature is disabled.
     *
     * @param  list<string>  $routes
     * @return list<string>
     */
    private function audienceRouteNames(array $routes, bool $attachmentsEnabled): array
    {
        if ($attachmentsEnabled) {
            return $routes;
        }

        return array_values(array_filter(
            $routes,
            static fn (string $route): bool => ! str_starts_with($route, 'attachments.'),
        ));
    }

    /**
     * @param  list<string>  $allowed
     */
    private function enumListReady(mixed $values, array $allowed): bool
    {
        if (! is_array($values) || ! $this->stringListReady($values, 64)) {
            return false;
        }

        foreach ($values as $value) {
            if (! in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function stringListReady(
        mixed $values,
        int $maximumLength,
        bool $allowEmpty = false,
    ): bool {
        if (! is_array($values)
            || ! array_is_list($values)
            || (! $allowEmpty && $values === [])) {
            return false;
        }

        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)
                || ! mb_check_encoding($value, 'UTF-8')
                || preg_match('/\S/u', $value) !== 1
                || mb_strlen($value) > $maximumLength
                || isset($seen[$value])) {
                return false;
            }

            $seen[$value] = true;
        }

        return true;
    }

    /**
     * Resolve the active authorization boundary without making the command fail hard.
     */
    private function authorization(Container $container): ?CommentAuthorization
    {
        try {
            return $container->make(CommentAuthorization::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the active trusted read scope without making diagnostics fail hard.
     */
    private function queryScope(Container $container): ?CommentQueryScope
    {
        try {
            return $container->make(CommentQueryScope::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Confirm one package boundary resolves to its declared contract.
     *
     * @param  class-string  $contract
     */
    private function contractReady(Container $container, string $contract): bool
    {
        try {
            return $container->make($contract) instanceof $contract;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Confirm a viewer-aware route group is guarded by authentication middleware.
     */
    private function authenticatedMiddlewareReady(string $group): bool
    {
        return $this->routeGroupMiddlewareReady($group, 'auth');
    }

    /**
     * Confirm an externally reachable route group is guarded by rate limiting.
     */
    private function throttledMiddlewareReady(string $group): bool
    {
        return $this->routeGroupMiddlewareReady($group, 'throttle');
    }

    /**
     * Confirm configured and registered routes consistently carry one middleware family.
     */
    private function routeGroupMiddlewareReady(string $group, string $middlewareName): bool
    {
        try {
            $configured = CommentsRouteConfiguration::middleware($group);
            $namePrefix = CommentsRouteConfiguration::name($group);

            if (! $this->containsMiddleware($configured, $middlewareName)) {
                return false;
            }

            $found = false;

            foreach (Route::getRoutes()->getRoutes() as $route) {
                $routeName = $route->getName();

                if (! is_string($routeName) || ! str_starts_with($routeName, $namePrefix)) {
                    continue;
                }

                $found = true;

                if (! $this->containsMiddleware(
                    $route->gatherMiddleware(),
                    $middlewareName,
                )) {
                    return false;
                }
            }

            return $found;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Determine whether a middleware list contains one middleware family.
     *
     * @param  array<array-key, mixed>  $middleware
     */
    private function containsMiddleware(array $middleware, string $name): bool
    {
        foreach ($middleware as $value) {
            if (is_string($value)
                && ($value === $name || str_starts_with($value, "{$name}:"))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Confirm opaque signed attachment delivery is enabled and fully registered.
     */
    private function attachmentRoutesReady(): bool
    {
        if (config('comments.routes.attachments.enabled', true) !== true) {
            return false;
        }

        try {
            $namePrefix = CommentsRouteConfiguration::name('attachments');
            $middleware = CommentsRouteConfiguration::middleware('attachments');
        } catch (Throwable) {
            return false;
        }

        if ($middleware === [] || ! $this->throttledMiddlewareReady('attachments')) {
            return false;
        }

        foreach (self::ATTACHMENT_ROUTE_NAMES as $routeName) {
            $route = Route::getRoutes()->getByName($namePrefix.$routeName);

            if ($route === null
                || ! in_array('signed', $route->gatherMiddleware(), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Confirm every enabled route exists behind middleware and has a usable target alias.
     *
     * @param  list<string>  $routeNames
     */
    private function routesReady(
        string $group,
        array $routeNames,
        CommentTargetRegistry $targets,
    ): bool {
        try {
            $namePrefix = CommentsRouteConfiguration::name($group);
            $middleware = CommentsRouteConfiguration::middleware($group);
        } catch (Throwable) {
            return false;
        }

        if ($middleware === [] || $targets->aliases() === []) {
            return false;
        }

        foreach ($routeNames as $routeName) {
            if (! Route::has($namePrefix.$routeName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate one production-critical column contract against portable schema metadata.
     *
     * @param  array{
     *     name: string,
     *     type: string,
     *     type_name: string,
     *     nullable: bool,
     *     default: mixed
     * }  $column
     * @param  array{
     *     kind: string,
     *     nullable: bool,
     *     length?: positive-int,
     *     default?: bool|int|string|null
     * }  $definition
     */
    private function columnDefinitionReady(
        array $column,
        array $definition,
        string $driver,
    ): bool {
        if ($column['nullable'] !== $definition['nullable']
            || ! $this->columnTypeReady($column, $definition['kind'], $driver)) {
            return false;
        }

        if (isset($definition['length'])
            && ! $this->columnLengthReady($column, $definition['length'], $driver)) {
            return false;
        }

        return ! array_key_exists('default', $definition)
            || $this->columnDefaultReady($column['default'], $definition['default']);
    }

    /**
     * Match Laravel's logical column kinds to every first-party database driver.
     *
     * @param  array{type: string, type_name: string}  $column
     */
    private function columnTypeReady(array $column, string $kind, string $driver): bool
    {
        $typeName = strtolower(trim($column['type_name']));
        $type = strtolower(trim($column['type']));
        $expectedTypeNames = match ($kind) {
            'uuid' => match ($driver) {
                'sqlite' => ['varchar'],
                'mysql' => ['char'],
                'mariadb' => ['char', 'uuid'],
                'pgsql' => ['uuid'],
                'sqlsrv' => ['uniqueidentifier'],
                default => [],
            },
            'string' => match ($driver) {
                'sqlite', 'mysql', 'mariadb', 'pgsql' => ['varchar'],
                'sqlsrv' => ['nvarchar'],
                default => [],
            },
            'fixed_string' => match ($driver) {
                'sqlite' => ['varchar'],
                'mysql', 'mariadb' => ['char'],
                'pgsql' => ['bpchar'],
                'sqlsrv' => ['nchar'],
                default => [],
            },
            'unsigned_small_integer' => match ($driver) {
                'sqlite' => ['integer'],
                'mysql', 'mariadb', 'sqlsrv' => ['smallint'],
                'pgsql' => ['int2'],
                default => [],
            },
            'unsigned_integer' => match ($driver) {
                'sqlite' => ['integer'],
                'mysql', 'mariadb', 'sqlsrv' => ['int'],
                'pgsql' => ['int4'],
                default => [],
            },
            'unsigned_big_integer' => match ($driver) {
                'sqlite' => ['integer'],
                'mysql', 'mariadb', 'sqlsrv' => ['bigint'],
                'pgsql' => ['int8'],
                default => [],
            },
            'boolean' => match ($driver) {
                'sqlite', 'mysql', 'mariadb' => ['tinyint'],
                'pgsql' => ['bool'],
                'sqlsrv' => ['bit'],
                default => [],
            },
            'timestamp' => match ($driver) {
                'sqlite' => ['datetime'],
                'mysql', 'mariadb', 'pgsql' => ['timestamp'],
                'sqlsrv' => ['datetime', 'datetime2'],
                default => [],
            },
            default => [],
        };

        if (! in_array($typeName, $expectedTypeNames, true)) {
            return false;
        }

        if ($kind === 'uuid'
            && in_array($driver, ['mysql', 'mariadb'], true)
            && $typeName === 'char'
            && $this->typeLength($type) !== 36) {
            return false;
        }

        if ($kind === 'boolean'
            && in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)
            && $this->typeLength($type) !== 1) {
            return false;
        }

        return ! str_starts_with($kind, 'unsigned_')
            || ! in_array($driver, ['mysql', 'mariadb'], true)
            || str_contains($type, ' unsigned');
    }

    /**
     * Validate enforced string lengths while respecting SQL Server's byte metadata.
     *
     * SQLite intentionally omits varchar lengths and does not enforce them.
     *
     * @param  array{type: string, type_name: string}  $column
     * @param  positive-int  $expectedLength
     */
    private function columnLengthReady(
        array $column,
        int $expectedLength,
        string $driver,
    ): bool {
        if ($driver === 'sqlite') {
            return true;
        }

        $typeName = strtolower(trim($column['type_name']));

        if ($driver === 'sqlsrv' && in_array($typeName, ['nchar', 'nvarchar'], true)) {
            $expectedLength *= 2;
        }

        return $this->typeLength($column['type']) === $expectedLength;
    }

    /**
     * Extract one numeric size from normalized database type metadata.
     */
    private function typeLength(string $type): ?int
    {
        if (preg_match('/\((\d+)\)/', $type, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Compare scalar defaults after removing driver-specific quoting and casts.
     */
    private function columnDefaultReady(mixed $actual, bool|int|string|null $expected): bool
    {
        if ($expected === null) {
            return $actual === null
                || (is_string($actual) && strtoupper(trim($actual)) === 'NULL');
        }

        if ($actual === null) {
            return false;
        }

        if (is_bool($actual)) {
            $normalized = $actual ? 'true' : 'false';
        } elseif (is_int($actual) || is_float($actual) || is_string($actual)) {
            $normalized = $this->normalizeColumnDefault((string) $actual);
        } else {
            return false;
        }

        if (is_bool($expected)) {
            return in_array(
                strtolower($normalized),
                $expected ? ['1', 'true'] : ['0', 'false'],
                true,
            );
        }

        if (is_int($expected)) {
            return preg_match('/^-?\d+$/', $normalized) === 1
                && (int) $normalized === $expected;
        }

        if ($expected === 'current_timestamp') {
            $normalized = strtolower(str_replace(' ', '', $normalized));

            return in_array(
                $normalized,
                ['current_timestamp', 'current_timestamp()', 'getdate()', 'now()'],
                true,
            );
        }

        return $normalized === $expected;
    }

    /**
     * Normalize simple defaults exposed differently by database schema inspectors.
     */
    private function normalizeColumnDefault(string $default): string
    {
        $normalized = trim($default);

        do {
            $previous = $normalized;

            if (strlen($normalized) >= 2
                && str_starts_with($normalized, '(')
                && str_ends_with($normalized, ')')) {
                $normalized = trim(substr($normalized, 1, -1));
            }

            $withoutCast = preg_replace(
                '/::[a-z_][a-z0-9_]*(?:\s+[a-z_][a-z0-9_]*)?(?:\(\d+\))?$/i',
                '',
                $normalized,
            );

            if (is_string($withoutCast)) {
                $normalized = trim($withoutCast);
            }
        } while ($normalized !== $previous);

        if (strlen($normalized) >= 2
            && ($normalized[0] === 'N' || $normalized[0] === 'n')
            && $normalized[1] === "'") {
            $normalized = substr($normalized, 1);
        }

        if (strlen($normalized) >= 2) {
            $quote = $normalized[0];

            if (($quote === "'" || $quote === '"')
                && str_ends_with($normalized, $quote)) {
                $normalized = substr($normalized, 1, -1);
                $normalized = str_replace($quote.$quote, $quote, $normalized);
            }
        }

        return trim($normalized);
    }

    /**
     * @param  list<array{
     *     name: string,
     *     columns: list<string>,
     *     type: string,
     *     unique: bool,
     *     primary: bool
     * }>  $indexes
     * @param  list<string>  $requiredColumns
     */
    private function indexReady(
        array $indexes,
        array $requiredColumns,
        bool $unique,
        bool $primary,
    ): bool {
        foreach ($indexes as $index) {
            $columns = array_map(strtolower(...), $index['columns']);

            if ($columns === $requiredColumns
                && (! $unique || $index['unique'])
                && (! $primary || $index['primary'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{
     *     name: string|null,
     *     columns: list<string>,
     *     foreign_schema: string|null,
     *     foreign_table: string,
     *     foreign_columns: list<string>,
     *     on_update: string|null,
     *     on_delete: string|null
     * }>  $foreignKeys
     * @param  list<string>  $requiredColumns
     */
    private function foreignKeyReady(
        array $foreignKeys,
        array $requiredColumns,
        string $commentsTable,
    ): bool {
        foreach ($foreignKeys as $foreignKey) {
            if (array_map(strtolower(...), $foreignKey['columns']) === $requiredColumns
                && strtolower($foreignKey['foreign_table']) === strtolower($commentsTable)
                && array_map(strtolower(...), $foreignKey['foreign_columns']) === ['id']
                && strtolower((string) $foreignKey['on_delete']) === 'cascade') {
                return true;
            }
        }

        return false;
    }
}
