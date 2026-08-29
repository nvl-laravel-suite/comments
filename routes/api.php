<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Comments\Http\Controllers\CommentAttachmentAssetController;
use Nvl\Comments\Http\Controllers\CommentsManagementController;
use Nvl\Comments\Http\Controllers\MemberCommentsController;
use Nvl\Comments\Http\Controllers\PublicCommentsController;
use Nvl\Comments\Http\Middleware\CommentsResponseCache;
use Nvl\Comments\Support\CommentsRouteConfiguration;

$attachmentRouteName = CommentsRouteConfiguration::name('attachments');
$attachmentsEnabled = config('comments.attachments.enabled', true) === true;

if ($attachmentsEnabled
    && config('comments.routes.attachments.enabled', true) === true
    && ! Route::has("{$attachmentRouteName}asset")) {
    Route::prefix(CommentsRouteConfiguration::path('attachments'))
        ->name($attachmentRouteName)
        ->middleware([
            CommentsResponseCache::class,
            ...CommentsRouteConfiguration::middleware('attachments'),
            'signed',
        ])
        ->group(function (): void {
            Route::get(
                '/{association}/asset',
                [CommentAttachmentAssetController::class, 'asset'],
            )
                ->whereUuid('association')
                ->name('asset');
            Route::get(
                '/{association}/thumbnail',
                [CommentAttachmentAssetController::class, 'thumbnail'],
            )
                ->whereUuid('association')
                ->name('thumbnail');
        });
}

if (config('comments.routes.public.enabled', false) === true) {
    Route::prefix(CommentsRouteConfiguration::path('public'))
        ->name(CommentsRouteConfiguration::name('public'))
        ->middleware([
            CommentsResponseCache::class,
            ...CommentsRouteConfiguration::middleware('public'),
        ])
        ->group(function () use ($attachmentsEnabled): void {
            Route::get('/targets/{target}/{targetId}', [PublicCommentsController::class, 'index'])
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->name('index');
            Route::post('/targets/{target}/{targetId}', [PublicCommentsController::class, 'store'])
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->name('store');
            Route::get('/comments/{comment}', [PublicCommentsController::class, 'show'])
                ->whereUuid('comment')->name('show');
            Route::match(
                ['put', 'patch'],
                '/comments/{comment}',
                [PublicCommentsController::class, 'update'],
            )
                ->whereUuid('comment')->name('update');
            Route::delete('/comments/{comment}', [PublicCommentsController::class, 'destroy'])
                ->whereUuid('comment')->name('destroy');
            Route::put('/comments/{comment}/reaction', [PublicCommentsController::class, 'react'])
                ->whereUuid('comment')->name('reaction');
            Route::post('/comments/{comment}/reports', [PublicCommentsController::class, 'report'])
                ->whereUuid('comment')->name('reports.store');
            if ($attachmentsEnabled) {
                Route::get(
                    '/comments/{comment}/attachments',
                    [PublicCommentsController::class, 'attachments'],
                )
                    ->whereUuid('comment')->name('attachments.index');
                Route::post(
                    '/comments/{comment}/attachments',
                    [PublicCommentsController::class, 'attach'],
                )
                    ->whereUuid('comment')->name('attachments.store');
                Route::delete(
                    '/comments/{comment}/attachments/{association}',
                    [PublicCommentsController::class, 'detach'],
                )
                    ->whereUuid('comment')
                    ->whereUuid('association')
                    ->name('attachments.destroy');
            }
        });
}

if (config('comments.routes.member.enabled', false) === true) {
    Route::prefix(CommentsRouteConfiguration::path('member'))
        ->name(CommentsRouteConfiguration::name('member'))
        ->middleware([
            CommentsResponseCache::class,
            ...CommentsRouteConfiguration::middleware('member'),
        ])
        ->group(function () use ($attachmentsEnabled): void {
            Route::get('/targets/{target}/{targetId}', [MemberCommentsController::class, 'index'])
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->name('index');
            Route::post('/targets/{target}/{targetId}', [MemberCommentsController::class, 'store'])
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->name('store');
            Route::post(
                '/targets/{target}/{targetId}/rich',
                [MemberCommentsController::class, 'storeRich'],
            )
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->name('rich.store');
            Route::get(
                '/targets/{target}/{targetId}/mentions/{resource}/suggestions',
                [MemberCommentsController::class, 'mentionSuggestions'],
            )
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->where('resource', '[a-z][a-z0-9_.-]{0,99}')
                ->name('mentions.suggestions');
            Route::get('/comments/{comment}', [MemberCommentsController::class, 'show'])
                ->whereUuid('comment')->name('show');
            Route::match(
                ['put', 'patch'],
                '/comments/{comment}',
                [MemberCommentsController::class, 'update'],
            )
                ->whereUuid('comment')->name('update');
            Route::match(
                ['put', 'patch'],
                '/comments/{comment}/rich',
                [MemberCommentsController::class, 'updateRich'],
            )
                ->whereUuid('comment')->name('rich.update');
            Route::delete('/comments/{comment}', [MemberCommentsController::class, 'destroy'])
                ->whereUuid('comment')->name('destroy');
            Route::post('/comments/{comment}/restore', [MemberCommentsController::class, 'restore'])
                ->whereUuid('comment')->name('restore');
            Route::put('/comments/{comment}/reaction', [MemberCommentsController::class, 'react'])
                ->whereUuid('comment')->name('reaction');
            Route::post('/comments/{comment}/reports', [MemberCommentsController::class, 'report'])
                ->whereUuid('comment')->name('reports.store');
            if ($attachmentsEnabled) {
                Route::get(
                    '/comments/{comment}/attachments',
                    [MemberCommentsController::class, 'attachments'],
                )
                    ->whereUuid('comment')->name('attachments.index');
                Route::post(
                    '/comments/{comment}/attachments',
                    [MemberCommentsController::class, 'attach'],
                )
                    ->whereUuid('comment')->name('attachments.store');
                Route::delete(
                    '/comments/{comment}/attachments/{association}',
                    [MemberCommentsController::class, 'detach'],
                )
                    ->whereUuid('comment')
                    ->whereUuid('association')
                    ->name('attachments.destroy');
            }
            Route::get(
                '/comments/{comment}/revisions',
                [MemberCommentsController::class, 'revisions'],
            )
                ->whereUuid('comment')->name('revisions.index');
            Route::post(
                '/comments/{comment}/revisions/{revision}/restore',
                [MemberCommentsController::class, 'restoreRevision'],
            )
                ->whereUuid('comment')
                ->whereUuid('revision')
                ->name('revisions.restore');
        });
}

if (config('comments.routes.management.enabled', false) === true) {
    Route::prefix(CommentsRouteConfiguration::path('management'))
        ->name(CommentsRouteConfiguration::name('management'))
        ->middleware([
            CommentsResponseCache::class,
            ...CommentsRouteConfiguration::middleware('management'),
        ])
        ->group(function () use ($attachmentsEnabled): void {
            Route::get(
                '/targets/{target}/{targetId}',
                [CommentsManagementController::class, 'index'],
            )
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->name('index');
            Route::post(
                '/targets/{target}/{targetId}/rich',
                [CommentsManagementController::class, 'storeRich'],
            )
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->name('rich.store');
            Route::get(
                '/targets/{target}/{targetId}/mentions/{resource}/suggestions',
                [CommentsManagementController::class, 'mentionSuggestions'],
            )
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->where('resource', '[a-z][a-z0-9_.-]{0,99}')
                ->name('mentions.suggestions');
            Route::get(
                '/targets/{target}/{targetId}/reports',
                [CommentsManagementController::class, 'targetReports'],
            )
                ->where('target', '[a-z][a-z0-9_.-]{0,99}')
                ->name('target_reports.index');
            Route::put('/{comment}/moderation', [CommentsManagementController::class, 'moderate'])
                ->whereUuid('comment')->name('moderate');
            Route::match(
                ['put', 'patch'],
                '/{comment}/rich',
                [CommentsManagementController::class, 'updateRich'],
            )
                ->whereUuid('comment')->name('rich.update');
            Route::post('/{comment}/restore', [CommentsManagementController::class, 'restore'])
                ->whereUuid('comment')->name('restore');
            Route::post('/{comment}/anonymize', [CommentsManagementController::class, 'anonymize'])
                ->whereUuid('comment')->name('anonymize');
            if ($attachmentsEnabled) {
                Route::get(
                    '/{comment}/attachments',
                    [CommentsManagementController::class, 'attachments'],
                )
                    ->whereUuid('comment')->name('attachments.index');
                Route::delete(
                    '/{comment}/attachments/{association}',
                    [CommentsManagementController::class, 'detach'],
                )
                    ->whereUuid('comment')
                    ->whereUuid('association')
                    ->name('attachments.destroy');
            }
            Route::get(
                '/{comment}/revisions',
                [CommentsManagementController::class, 'revisions'],
            )
                ->whereUuid('comment')->name('revisions.index');
            Route::post(
                '/{comment}/revisions/{revision}/restore',
                [CommentsManagementController::class, 'restoreRevision'],
            )
                ->whereUuid('comment')
                ->whereUuid('revision')
                ->name('revisions.restore');
            Route::get('/{comment}/reports', [CommentsManagementController::class, 'reports'])
                ->whereUuid('comment')->name('reports.index');
            Route::put('/reports/{report}', [CommentsManagementController::class, 'resolveReport'])
                ->whereUuid('report')->name('reports.resolve');
        });
}
