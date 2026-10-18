<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Native host owner without an NVL tenancy or authentication package. */
final class OwnershipCompany extends Model
{
    protected $table = 'comments_owned_companies';

    protected $guarded = [];

    public $timestamps = false;
}
