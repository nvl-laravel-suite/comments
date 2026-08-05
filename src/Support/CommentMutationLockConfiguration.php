<?php

declare(strict_types=1);

namespace Nvl\Comments\Support;

use Nvl\Comments\Exceptions\CommentMutationLockConfigurationException;

/**
 * Validates the complete comment mutation-lock configuration without coercion.
 */
final class CommentMutationLockConfiguration
{
    /**
     * Resolve the exact mutation-lock settings required by runtime and diagnostics.
     *
     * @return array{
     *     enabled: bool,
     *     store: non-empty-string|null,
     *     seconds: positive-int,
     *     wait_seconds: positive-int,
     *     allow_local_store: bool
     * }
     *
     * @throws CommentMutationLockConfigurationException
     */
    public static function settings(): array
    {
        $configuration = config('comments.mutation_lock');

        if (! is_array($configuration)) {
            throw new CommentMutationLockConfigurationException(
                'comments.mutation_lock must be an array.',
            );
        }

        $enabled = $configuration['enabled'] ?? null;
        $store = $configuration['store'] ?? null;
        $seconds = $configuration['seconds'] ?? null;
        $waitSeconds = $configuration['wait_seconds'] ?? null;
        $allowLocalStore = $configuration['allow_local_store'] ?? null;

        if (! is_bool($enabled)) {
            throw new CommentMutationLockConfigurationException(
                'comments.mutation_lock.enabled must be a boolean.',
            );
        }

        if ($store === null) {
            $storeName = null;
        } elseif (! is_string($store)) {
            throw new CommentMutationLockConfigurationException(
                'comments.mutation_lock.store must be null or a non-blank cache store name.',
            );
        } else {
            $storeName = trim($store);

            if ($storeName === '') {
                throw new CommentMutationLockConfigurationException(
                    'comments.mutation_lock.store must be null or a non-blank cache store name.',
                );
            }
        }

        if (! is_int($seconds) || $seconds < 1) {
            throw new CommentMutationLockConfigurationException(
                'comments.mutation_lock.seconds must be a positive integer.',
            );
        }

        if (! is_int($waitSeconds) || $waitSeconds < 1) {
            throw new CommentMutationLockConfigurationException(
                'comments.mutation_lock.wait_seconds must be a positive integer.',
            );
        }

        if (! is_bool($allowLocalStore)) {
            throw new CommentMutationLockConfigurationException(
                'comments.mutation_lock.allow_local_store must be a boolean.',
            );
        }

        return [
            'enabled' => $enabled,
            'store' => $storeName,
            'seconds' => $seconds,
            'wait_seconds' => $waitSeconds,
            'allow_local_store' => $allowLocalStore,
        ];
    }

    private function __construct() {}
}
