<?php

declare(strict_types=1);

use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;

return [
    'models' => ['comment' => Comment::class],
    'ownership' => ['target_resources' => []], 'tenancy' => ['target_types' => []], 'connection' => null, 'tables' => [CommentsTables::Comments => CommentsTables::Comments, CommentsTables::Reactions => CommentsTables::Reactions, CommentsTables::Revisions => CommentsTables::Revisions, CommentsTables::Reports => CommentsTables::Reports], 'migrations' => ['enabled' => true], 'authorization' => ['class' => ConfiguredCommentAuthorization::class], 'routes' => ['management' => ['enabled' => false, 'prefix' => 'nvl/api/v1/comments', 'name' => 'nvl.comments.management.', 'middleware' => ['api', 'auth', 'throttle:60,1']], 'member' => ['enabled' => false, 'prefix' => 'nvl/api/v1/comments/member', 'name' => 'nvl.comments.member.', 'middleware' => ['api', 'auth', 'throttle:60,1']], 'public' => ['enabled' => false, 'prefix' => 'nvl/api/v1/comments/public', 'name' => 'nvl.comments.public.', 'middleware' => ['api', 'throttle:60,1']], 'attachments' => ['enabled' => true, 'prefix' => 'nvl/api/v1/comments/attachments', 'name' => 'nvl.comments.attachments.', 'middleware' => ['api', 'throttle:120,1']]], 'anonymous' => ['enabled' => false], 'mentions' => ['enabled' => false], 'attachments' => ['enabled' => null], 'mutation_lock' => ['enabled' => true, 'store' => null]];
