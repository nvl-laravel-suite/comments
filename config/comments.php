<?php

declare(strict_types=1);

use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;
use Nvl\Comments\Services\SafeCommentAuthorPresenter;
use Nvl\Comments\Support\CommentActorFactory;

return [
    'connection' => null,

    'tables' => [
        CommentsTables::Comments => CommentsTables::Comments,
        CommentsTables::Reactions => CommentsTables::Reactions,
        CommentsTables::Revisions => CommentsTables::Revisions,
        CommentsTables::Reports => CommentsTables::Reports,
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

    'actor_resolver' => [
        'class' => CommentActorFactory::class,
    ],

    'author_presenter' => [
        'class' => SafeCommentAuthorPresenter::class,
    ],

    'targets' => [],

    'routes' => [
        'management' => [
            'enabled' => false,
            'prefix' => 'api/v1/comments',
            'name' => 'nvl.comments.management.',
            'middleware' => ['api', 'auth', 'throttle:60,1'],
        ],
        'member' => [
            'enabled' => false,
            'prefix' => 'api/v1/member/discussions',
            'name' => 'nvl.comments.member.',
            'middleware' => ['api', 'auth', 'throttle:60,1'],
        ],
        'public' => [
            'enabled' => false,
            'prefix' => 'api/v1/discussions',
            'name' => 'nvl.comments.public.',
            'middleware' => ['api', 'throttle:60,1'],
        ],
        'attachments' => [
            'enabled' => true,
            'prefix' => 'api/v1/comment-attachments',
            'name' => 'nvl.comments.attachments.',
            'middleware' => ['api', 'throttle:120,1'],
        ],
    ],

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
        'digest_key' => env('COMMENTS_IDEMPOTENCY_DIGEST_KEY'),
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
        'digest_key' => env('COMMENTS_METADATA_DIGEST_KEY'),
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
        'enabled' => true,
        'maximum_per_comment' => 5,
        'maximum_file_bytes' => 10 * 1024 * 1024,
        'allow_public_media' => false,
        'signed_url_lifetime' => 5,
    ],

    'mutation_lock' => [
        'enabled' => true,
        'store' => null,
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

    'cache' => [
        'public_max_age' => 60,
    ],
];
