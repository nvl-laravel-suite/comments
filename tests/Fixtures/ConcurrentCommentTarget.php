<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Comments\Support\CommentsConfiguration;

/**
 * Reads a persisted test target through each process-local race connection.
 */
final class ConcurrentCommentTarget extends Model
{
    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /**
     * Reuse the committed Comments table as a concurrency target fixture.
     */
    public function getTable(): string
    {
        return CommentsConfiguration::table('comments');
    }
}
