<?php

declare(strict_types=1);
use Nvl\Comments\Definitions\Tables\CommentsTables;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;

/** Common published defaults; advanced defaults remain in the package configuration. */
return ['models' => ['comment' => Comment::class], 'ownership' => ['target_resources' => []], 'connection' => null, 'tables' => ['comments' => CommentsTables::Comments, 'reactions' => CommentsTables::Reactions, 'revisions' => CommentsTables::Revisions, 'reports' => CommentsTables::Reports, 'metadata_values' => CommentsTables::MetadataValues, 'mentions' => CommentsTables::Mentions], 'migrations' => ['enabled' => true], 'authorization' => ['class' => ConfiguredCommentAuthorization::class], 'anonymous' => ['enabled' => false], 'mentions' => ['enabled' => false], 'attachments' => ['enabled' => null], 'mutation_lock' => ['enabled' => true], 'locks' => ['mutation' => ['store' => null]]];
