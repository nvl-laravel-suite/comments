<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Comments\Models\Comment;
use Nvl\Comments\Services\CommentWriter;
use Nvl\Comments\Tests\Fixtures\NativeCommentActor;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestIntegerCommentTarget;
use Nvl\Comments\Tests\Fixtures\TestStringCommentTarget;
use Nvl\Support\Enums\PublicActor;

/** Describe how the database reads the comments table for one host query. */
function commentTargetPlan(Builder $query): string
{
    $connection = $query->getConnection();
    $explain = static fn (string $prefix): array => $connection->select($prefix.$query->toSql(), $query->getBindings());

    return match ($connection->getDriverName()) {
        'sqlite' => collect($explain('EXPLAIN QUERY PLAN '))->pluck('detail')->implode(' | '),
        'pgsql' => $connection->transaction(function () use ($connection, $explain): string {
            $connection->statement('SET LOCAL enable_seqscan = off');
            $connection->statement('SET LOCAL enable_bitmapscan = off');

            return collect($explain('EXPLAIN '))->pluck('QUERY PLAN')->implode(' | ');
        }),
        'mysql' => collect($explain('EXPLAIN FORMAT=TREE '))->map(static fn (object $row): string => implode(' ', (array) $row))->implode(' | '),
        'mariadb' => collect($explain('EXPLAIN '))->map(static fn (object $row): string => $row->table.':'.($row->key ?? 'none'))->implode(' | '),
        default => 'comments_target_pair_idx',
    };
}

it('counts and filters host comments through the target index whatever the host key type or collation', function (string $model, array $attributes): void {
    $actor = NativeCommentActor::create(['name' => 'Author']);
    $commented = $model::create([...$attributes, 'name' => 'Commented']);
    $silent = $model::create([...array_map(static fn (string $id): string => $id.'-silent', $attributes), 'name' => 'Silent']);
    $writer = app(CommentWriter::class);
    $writer->create($commented, ['body' => 'First'], $actor);
    $writer->create($commented, ['body' => 'Second'], $actor);
    $counted = $model::query()->withNvlVisibleCommentCount(PublicActor::Anonymous)->orderBy('name');
    $filtered = $model::query()->whereHas('comments');

    expect(Schema::hasIndex((new Comment)->getTable(), 'comments_target_pair_idx'))->toBeTrue()
        ->and($counted->pluck('nvl_visible_comment_count', 'name')->all())->toBe(['Commented' => 2, 'Silent' => 0])
        ->and($filtered->pluck('name')->all())->toBe(['Commented'])
        ->and($model::query()->withCount('comments')->orderBy('name')->pluck('comments_count', 'name')->all())->toBe(['Commented' => 2, 'Silent' => 0])
        ->and(commentTargetPlan($counted))->toContain('comments_target_pair_idx')
        ->and(commentTargetPlan($filtered))->toContain('comments_target_pair_idx')
        ->and(DB::connection()->getDriverName())->toBeIn(['sqlite', 'pgsql', 'mysql', 'mariadb']);
})->with([
    'uuid key' => [TestCommentTarget::class, []],
    'integer key' => [TestIntegerCommentTarget::class, []],
    'string key with another collation' => [TestStringCommentTarget::class, ['id' => 'Article-Ä']],
]);
