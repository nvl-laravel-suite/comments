<?php

declare(strict_types=1);

namespace Nvl\Comments\Support;

use InvalidArgumentException;

/**
 * Validates independently configurable public, member, and management routes.
 */
final class CommentsRouteConfiguration
{
    public static function path(string $group): string
    {
        $path = config("comments.routes.{$group}.prefix", "api/v1/comments/{$group}");

        if (! is_string($path)) {
            throw new InvalidArgumentException("comments.routes.{$group}.prefix must be a string.");
        }

        $path = trim($path, '/');

        if ($path === ''
            || str_contains($path, '..')
            || str_contains($path, '//')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]*$#', $path) !== 1) {
            throw new InvalidArgumentException(
                "comments.routes.{$group}.prefix must be a safe route prefix.",
            );
        }

        return $path;
    }

    public static function name(string $group): string
    {
        $name = config("comments.routes.{$group}.name", "nvl.comments.{$group}.");

        if (! is_string($name)) {
            throw new InvalidArgumentException("comments.routes.{$group}.name must be a string.");
        }

        $name = rtrim(trim($name), '.');

        if ($name === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                "comments.routes.{$group}.name must be a safe route-name prefix.",
            );
        }

        return $name.'.';
    }

    /**
     * @return list<string>
     */
    public static function middleware(string $group): array
    {
        $middleware = config("comments.routes.{$group}.middleware", ['api']);

        if (! is_array($middleware)) {
            throw new InvalidArgumentException(
                "comments.routes.{$group}.middleware must be an array.",
            );
        }

        if (! array_is_list($middleware) || $middleware === []) {
            throw new InvalidArgumentException(
                "comments.routes.{$group}.middleware must be a non-empty list.",
            );
        }

        foreach ($middleware as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(
                    "comments.routes.{$group}.middleware entries must be non-blank strings.",
                );
            }
        }

        return $middleware;
    }

    private function __construct() {}
}
