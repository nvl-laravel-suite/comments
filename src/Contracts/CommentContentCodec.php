<?php

declare(strict_types=1);

namespace Nvl\Comments\Contracts;

/**
 * Projects a versioned source payload into non-executable comment text.
 *
 * @api
 */
interface CommentContentCodec
{
    /** Stable storage discriminator for this format. */
    public function identifier(): string;

    /** Version of the source format accepted by this codec. */
    public function version(): int;

    /**
     * Report unsupported node types alongside the plain-text projection.
     *
     * @param  string|array<array-key,mixed>  $payload
     * @return array{body:string, unsupported:list<string>}
     */
    public function project(string|array $payload): array;
}
