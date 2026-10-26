<?php

declare(strict_types=1);
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;

/** Common published defaults; advanced defaults remain in the package configuration. */
return ['models' => ['comment' => Comment::class], 'ownership' => ['target_resources' => []], 'connection' => null, 'tables' => [CommentsTables::Comments => CommentsTables::Comments, CommentsTables::Reactions => CommentsTables::Reactions, CommentsTables::Revisions => CommentsTables::Revisions, CommentsTables::Reports => CommentsTables::Reports], 'migrations' => ['enabled' => true], 'authorization' => ['class' => ConfiguredCommentAuthorization::class], 'anonymous' => ['enabled' => false], 'mentions' => ['enabled' => false], 'attachments' => ['enabled' => null], 'mutation_lock' => ['enabled' => true, 'store' => null]];
