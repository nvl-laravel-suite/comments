<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Nvl\Comments\Exceptions\CommentAttachmentDeliveryUnavailableException;
use Nvl\Comments\Support\CommentsConfiguration;
use Nvl\Comments\Support\CommentsRouteConfiguration;
use Nvl\Media\Models\MediaAssociation;
use Throwable;

/**
 * Generates short-lived association-scoped URLs without exposing Media internals.
 */
final class CommentAttachmentUrlFactory
{
    /**
     * Require both signed delivery endpoints before any HTTP attachment mutation begins.
     */
    public function assertAvailable(): void
    {
        if (config('comments.attachments.enabled', true) !== true
            || config('comments.routes.attachments.enabled', true) !== true) {
            throw new CommentAttachmentDeliveryUnavailableException;
        }

        try {
            $namePrefix = CommentsRouteConfiguration::name('attachments');
        } catch (Throwable) {
            throw new CommentAttachmentDeliveryUnavailableException;
        }

        foreach (['asset', 'thumbnail'] as $routeName) {
            $route = Route::getRoutes()->getByName($namePrefix.$routeName);

            if ($route === null
                || ! in_array('signed', $route->gatherMiddleware(), true)) {
                throw new CommentAttachmentDeliveryUnavailableException;
            }
        }
    }

    /**
     * Generate a signed original-asset URL.
     */
    public function asset(MediaAssociation $association): string
    {
        return $this->signedUrl($association, 'asset');
    }

    /**
     * Generate a signed preferred-thumbnail URL.
     */
    public function thumbnail(MediaAssociation $association): string
    {
        return $this->signedUrl($association, 'thumbnail');
    }

    /**
     * Generate one temporary URL containing only the allowed association identity.
     */
    private function signedUrl(
        MediaAssociation $association,
        string $route,
    ): string {
        $this->assertAvailable();
        $lifetime = CommentsConfiguration::positiveInteger(
            'comments.attachments.signed_url_lifetime',
            5,
        );

        return URL::temporarySignedRoute(
            CommentsRouteConfiguration::name('attachments').$route,
            now()->addMinutes($lifetime),
            ['association' => $association->id],
        );
    }
}
