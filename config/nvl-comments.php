<?php

declare(strict_types=1);

use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'models' => ['comment' => Comment::class],
    'ownership' => ['target_resources' => []],

    'connection' => null,

    'tables' => [
        'comments' => CommentsTables::Comments,
        'reactions' => CommentsTables::Reactions,
        'revisions' => CommentsTables::Revisions,
        'reports' => CommentsTables::Reports,
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'authorization' => [
        'class' => ConfiguredCommentAuthorization::class,
    ],

    'query_scope' => [
        'class' => ConfiguredCommentAuthorization::class,
    ],

    'targets' => [],

    'moderation' => [
        'new_status' => 'pending',
        'edited_status' => 'pending',
        'restored_status' => 'pending',
        'actionable_statuses' => ['pending', 'spam'],
        'allow_author_delete' => true,
        'allow_author_restore' => true,
    ],

    'anonymous' => [
        'enabled' => false,
    ],

    'idempotency' => [
        'digest_key' => env('NVL_COMMENTS_IDEMPOTENCY_DIGEST_KEY'),
    ],

    'threading' => [
        'maximum_depth' => 6,
        'maximum_replies_per_page' => 100,
    ],

    'content' => [
        'maximum_bytes' => 20_000,
        'allowed_formats' => ['plain', 'markdown'],
        'maximum_tags' => 20,
    ],

    'metadata' => [
        'strict' => false,
        'maximum_bytes' => 16_384,
        'maximum_registered_fields' => 50,
        'digest_key' => env('NVL_COMMENTS_METADATA_DIGEST_KEY'),
        'schemas' => [],
    ],

    'rich_text' => [
        'maximum_bytes' => 32_768,
        'maximum_blocks' => 100,
        'maximum_nodes' => 500,
    ],

    'mentions' => [
        'enabled' => false,
        'maximum_per_comment' => 25,
        'maximum_resource_types_per_comment' => 10,
        'suggestion_limit' => 10,
        'maximum_suggestion_limit' => 20,
        'maximum_query_length' => 160,
        'maximum_batch_size' => 100,
        'resources' => [],
    ],

    'reactions' => [
        'allowed' => ['like', 'love', 'insightful', 'helpful'],
    ],

    'attachments' => [
        'enabled' => null,
        'maximum_per_comment' => 5,
        'maximum_file_bytes' => 10 * 1024 * 1024,
        'allow_public_media' => false,
        'signed_url_lifetime' => 5,
    ],

    'mutation_lock' => [
        'enabled' => true,
        'seconds' => 300,
        'wait_seconds' => 30,
        'allow_local_store' => false,
    ],

    'transactions' => [
        'attempts' => 3,
    ],

    'reconciliation' => [
        'chunk_size' => 500,
    ],

    'pagination' => [
        'default' => 25,
        'maximum' => 100,
    ],
    'locks' => ['mutation' => ['store' => null]],

];
