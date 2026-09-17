<?php

declare(strict_types=1);

namespace Nvl\Comments\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Tenancy\Contracts\TenantParentResolver;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;

/** Exposes the host's bounded canonical Comment target morph allowlist. */
final readonly class CommentTenantParentResolver implements TenantParentResolver
{
    public function __construct(private Repository $config) {}

    /** @return array<string, class-string<Model>> */
    public function types(): array
    {
        $configured = $this->config->get('comments.tenancy.target_types', []);

        if (! is_array($configured)) {
            throw new TenantConfigurationInvalid('comments.tenancy.target_types must be an alias-keyed model map.');
        }

        $types = [];
        foreach ($configured as $alias => $modelClass) {
            if (! is_string($alias) || $alias === '' || ! is_string($modelClass)
                || ! is_a($modelClass, Model::class, true)) {
                throw new TenantConfigurationInvalid('Every Comment tenant target type must be an explicit model mapping.');
            }
            $types[$alias] = $modelClass;
        }

        return $types;
    }
}
