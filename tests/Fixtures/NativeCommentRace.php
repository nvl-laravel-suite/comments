<?php

declare(strict_types=1);

namespace Nvl\Comments\Tests\Fixtures;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/** Two independently connected PHP processes released through the same start gate. */
final class NativeCommentRace
{
    /**
     * @param  list<Closure():array<string,bool|int|string>>  $workers
     * @return list<array<string,mixed>>
     */
    public static function run(array $workers): array
    {
        if (count($workers) !== 2) {
            throw new RuntimeException('Comment races require exactly two workers.');
        }
        $directory = sys_get_temp_dir().'/nvl-comment-race-'.bin2hex(random_bytes(6));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('The comment race could not allocate its IPC directory.');
        }
        DB::disconnect('comments_concurrency');
        Redis::purge('comments_concurrency');
        $children = [];
        try {
            foreach ($workers as $index => $worker) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('The comment race could not fork a worker.');
                }
                if ($pid > 0) {
                    $children[$pid] = true;

                    continue;
                }
                try {
                    DB::purge('comments_concurrency');
                    Redis::purge('comments_concurrency');
                    $driver = DB::connection()->getDriverName();
                    file_put_contents($directory.'/ready-'.$index, 'ready');
                    $deadline = microtime(true) + 10;
                    while (! is_file($directory.'/go')) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('The comment race start gate timed out.');
                        }
                        usleep(10_000);
                    }
                    $result = ['ok' => true, 'pid' => getmypid(), 'driver' => $driver, ...$worker()];
                } catch (Throwable $exception) {
                    $result = ['ok' => false, 'pid' => getmypid(), 'driver' => DB::connection()->getDriverName(), 'exception' => $exception::class, 'message' => $exception->getMessage()];
                }
                file_put_contents($directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                if (pcntl_exec('/usr/bin/true') === false) {
                    exit(1);
                }
            }
            $deadline = microtime(true) + 15;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The comment race workers did not become ready.');
                }
                usleep(10_000);
            }
            file_put_contents($directory.'/go', 'go');
            while ($children !== []) {
                foreach (array_keys($children) as $child) {
                    $status = 0;
                    $waited = pcntl_waitpid($child, $status, WNOHANG);
                    if ($waited === $child) {
                        unset($children[$child]);
                        if (! is_int($status) || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                            throw new RuntimeException('A comment race worker did not exit successfully.');
                        }
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The comment race workers did not finish.');
                }
                if ($children !== []) {
                    usleep(10_000);
                }
            }
            $results = [];
            foreach ([0, 1] as $index) {
                $payload = file_get_contents($directory.'/result-'.$index);
                $decoded = is_string($payload) ? json_decode($payload, true, flags: JSON_THROW_ON_ERROR) : null;
                if (! is_array($decoded)) {
                    throw new RuntimeException('A comment race worker returned invalid data.');
                }
                $row = [];
                foreach ($decoded as $key => $value) {
                    if (! is_string($key)) {
                        throw new RuntimeException('A comment race worker returned an invalid result key.');
                    }
                    $row[$key] = $value;
                }
                $results[] = $row;
            }

            return $results;
        } finally {
            foreach (array_keys($children) as $child) {
                posix_kill($child, SIGTERM);
                pcntl_waitpid($child, $status);
            }
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
