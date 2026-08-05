<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Data\Mutations\AnonymizeCommentData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Enums\CommentAudience;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Exceptions\CommentTargetNotFoundException;
use Nvl\Comments\Exceptions\InvalidCommentLifecycleException;
use Nvl\Comments\Exceptions\InvalidCommentMutationException;
use Nvl\Comments\Http\Middleware\CommentsResponseCache;
use Nvl\Comments\Services\CommentAttachmentAssetResponder;
use Nvl\Comments\Services\CommentContentGuard;
use Nvl\Comments\Services\CommentLifecycleGuard;
use Nvl\Comments\Services\CommentReadService;
use Nvl\Comments\Support\CommentsRouteConfiguration;
use Nvl\Comments\Tests\Fixtures\TestCommentTarget;
use Nvl\Media\Enums\MediaLifecycleStatus;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Persist one available media record and its corresponding object.
 */
function commentsCoverageAsset(
    string $disk,
    string $contents,
    string $filename = 'quarterly-report.txt',
): Media {
    $media = Media::factory()->create([
        'filename' => $filename,
        'hash' => hash('sha256', $disk.$contents).'.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size' => strlen($contents),
        'disk' => $disk,
        'folder' => 'comments-coverage',
        'is_public' => false,
        'visibility' => MediaVisibility::Private,
        'status' => MediaLifecycleStatus::Available,
        'type' => MediaType::OTHER,
    ]);

    Storage::disk($disk)->put($media->buildPath(), $contents);

    return $media;
}

/**
 * Execute the strict Doctor contract and decode its machine-readable report.
 *
 * @return array{int, array<string, mixed>}
 */
function commentsCoverageDoctor(): array
{
    $exitCode = Artisan::call('nvl:comments:doctor', [
        '--strict' => true,
        '--format' => 'json',
    ]);

    return [$exitCode, commentsCoverageJsonOutput()];
}

/**
 * Decode the latest Artisan output as one string-keyed JSON object.
 *
 * @return array<string, mixed>
 */
function commentsCoverageJsonOutput(): array
{
    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('The Comments command did not return a JSON object.');
    }

    $object = [];

    foreach ($decoded as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException('The Comments command returned a JSON list.');
        }

        $object[$key] = $value;
    }

    return $object;
}

it('serves local assets with private headers and safe unicode filenames', function (): void {
    Storage::fake('comments_local');
    config()->set('media.cache_file_existence', false);
    config()->set('filesystems.disks.comments_local.driver', 'local');

    $media = commentsCoverageAsset(
        'comments_local',
        'local-attachment',
        'résumé "quarterly".txt',
    );
    $response = app(CommentAttachmentAssetResponder::class)->serve(
        Request::create('/comment-asset'),
        $media,
    );

    expect($response)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/plain')
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain(
            'filename="r_sum_quarterly_.txt"',
            "filename*=UTF-8''r%C3%A9sum%C3%A9%20%22quarterly%22.txt",
        )
        ->and($response->headers->get('ETag'))->toBeString();
});

it('honors conditional requests and hides unavailable asset details', function (): void {
    Storage::fake('comments_conditional');
    config()->set('media.cache_file_existence', false);
    config()->set('filesystems.disks.comments_conditional.driver', 'local');

    $media = commentsCoverageAsset('comments_conditional', 'conditional-attachment');
    $responder = app(CommentAttachmentAssetResponder::class);
    $initial = $responder->serve(Request::create('/comment-asset'), $media);
    $etag = $initial->headers->get('ETag');

    expect($etag)->toBeString();

    $weakRequest = Request::create('/comment-asset');
    $weakRequest->headers->set('If-None-Match', '"unrelated", W/'.$etag);
    $weakResponse = $responder->serve($weakRequest, $media);

    $wildcardRequest = Request::create('/comment-asset');
    $wildcardRequest->headers->set('If-None-Match', '*');
    $wildcardResponse = $responder->serve($wildcardRequest, $media);

    expect($weakResponse->getStatusCode())->toBe(304)
        ->and($weakResponse->headers->get('ETag'))->toBe($etag)
        ->and($weakResponse->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($wildcardResponse->getStatusCode())->toBe(304);

    try {
        $responder->serve(Request::create('/comment-asset'), $media, 'missing-variation');
        throw new RuntimeException('An unavailable variation must not be disclosed.');
    } catch (Throwable $exception) {
        if (! method_exists($exception, 'getStatusCode')) {
            throw $exception;
        }

        expect($exception->getStatusCode())->toBe(404);
    }
});

it('streams complete and ranged non-local assets without loading them into memory', function (): void {
    Storage::fake('comments_remote');
    config()->set('media.cache_file_existence', false);

    $contents = '0123456789';
    $media = commentsCoverageAsset('comments_remote', $contents);

    // Keep the already-resolved test adapter while exercising the non-local delivery path.
    config()->set('filesystems.disks.comments_remote.driver', 's3');

    $responder = app(CommentAttachmentAssetResponder::class);
    $complete = $responder->serve(Request::create('/comment-asset'), $media);

    expect($complete)->toBeInstanceOf(StreamedResponse::class)
        ->and($complete->getStatusCode())->toBe(200)
        ->and($complete->headers->get('Content-Length'))->toBe('10')
        ->and($complete->headers->has('Content-Range'))->toBeFalse();

    ob_start();
    $complete->sendContent();
    $completeContents = ob_get_clean();

    $rangeRequest = Request::create('/comment-asset');
    $rangeRequest->headers->set('Range', 'bytes=2-5');
    $range = $responder->serve($rangeRequest, $media);

    expect($range)->toBeInstanceOf(StreamedResponse::class)
        ->and($range->getStatusCode())->toBe(206)
        ->and($range->headers->get('Content-Length'))->toBe('4')
        ->and($range->headers->get('Content-Range'))->toBe('bytes 2-5/10');

    ob_start();
    $range->sendContent();
    $rangeContents = ob_get_clean();

    $suffixRequest = Request::create('/comment-asset');
    $suffixRequest->headers->set('Range', 'bytes=-3');
    $suffix = $responder->serve($suffixRequest, $media);

    ob_start();
    $suffix->sendContent();
    $suffixContents = ob_get_clean();

    $openEndedRequest = Request::create('/comment-asset', 'HEAD');
    $openEndedRequest->headers->set('Range', 'bytes=7-');
    $head = $responder->serve($openEndedRequest, $media);

    expect($completeContents)->toBe($contents)
        ->and($rangeContents)->toBe('2345')
        ->and($suffixContents)->toBe('789')
        ->and($head->getStatusCode())->toBe(206)
        ->and($head->headers->get('Content-Length'))->toBe('3')
        ->and($head->headers->get('Content-Range'))->toBe('bytes 7-9/10')
        ->and($head->getContent())->toBe('');
});

it('rejects malformed or unsatisfiable byte ranges without leaking asset state', function (): void {
    Storage::fake('comments_ranges');
    config()->set('media.cache_file_existence', false);

    $media = commentsCoverageAsset('comments_ranges', '0123456789');
    config()->set('filesystems.disks.comments_ranges.driver', 's3');
    $responder = app(CommentAttachmentAssetResponder::class);

    foreach (['bytes=0-1,4-5', 'bytes=-0', 'bytes=10-', 'bytes=7-2'] as $range) {
        $request = Request::create('/comment-asset');
        $request->headers->set('Range', $range);

        try {
            $responder->serve($request, $media);
            throw new RuntimeException("Range [{$range}] should have been rejected.");
        } catch (Throwable $exception) {
            if (! method_exists($exception, 'getStatusCode')
                || ! method_exists($exception, 'getHeaders')) {
                throw $exception;
            }

            $headers = $exception->getHeaders();

            if (! is_array($headers)) {
                throw new RuntimeException('The range exception did not expose HTTP headers.');
            }

            expect($exception->getStatusCode())->toBe(416)
                ->and($headers['Content-Range'] ?? null)->toBe('bytes */10');
        }
    }
});

it('rejects malformed route configuration instead of weakening route boundaries', function (
    string $key,
    mixed $value,
    string $method,
    string $message,
): void {
    config()->set($key, $value);

    $resolve = static fn (): mixed => match ($method) {
        'path' => CommentsRouteConfiguration::path('public'),
        'name' => CommentsRouteConfiguration::name('public'),
        'middleware' => CommentsRouteConfiguration::middleware('public'),
        default => throw new InvalidArgumentException("Unsupported route configuration method [{$method}]."),
    };

    expect($resolve)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'non-string path' => ['comments.routes.public.prefix', ['api'], 'path', 'must be a string'],
    'blank path' => ['comments.routes.public.prefix', '///', 'path', 'safe route prefix'],
    'traversing path' => ['comments.routes.public.prefix', 'api/../private', 'path', 'safe route prefix'],
    'double-slash path' => ['comments.routes.public.prefix', 'api//private', 'path', 'safe route prefix'],
    'non-string name' => ['comments.routes.public.name', 42, 'name', 'must be a string'],
    'blank name' => ['comments.routes.public.name', '...', 'name', 'safe route-name prefix'],
    'non-array middleware' => ['comments.routes.public.middleware', 'api', 'middleware', 'must be an array'],
    'associative middleware' => [
        'comments.routes.public.middleware',
        ['transport' => 'api'],
        'middleware',
        'must be a non-empty list',
    ],
    'empty middleware' => ['comments.routes.public.middleware', [], 'middleware', 'must be a non-empty list'],
    'blank middleware' => ['comments.routes.public.middleware', ['api', '  '], 'middleware', 'non-blank strings'],
    'non-string middleware' => ['comments.routes.public.middleware', ['api', 42], 'middleware', 'non-blank strings'],
]);

it('enforces byte locale tag format and metadata limits at the direct action boundary', function (): void {
    $guard = app(CommentContentGuard::class);

    config()->set('comments.content.maximum_bytes', 4);
    expect(fn () => $guard->create(new CreateCommentData('12345')))
        ->toThrow(InvalidCommentMutationException::class, 'exceeds 4 bytes');

    config()->set('comments.content.maximum_bytes', 20_000);
    config()->set('comments.content.allowed_formats', ['plain']);
    expect(fn () => $guard->create(new CreateCommentData('Body', CommentFormat::Markdown)))
        ->toThrow(InvalidCommentMutationException::class, 'format [markdown] is not enabled');

    expect(fn () => $guard->create(new CreateCommentData('Body', locale: str_repeat('a', 36))))
        ->toThrow(InvalidCommentMutationException::class, 'at most 35 characters');

    config()->set('comments.content.allowed_formats', ['plain', 'markdown']);
    config()->set('comments.content.maximum_tags', 1);
    expect(fn () => $guard->create(new CreateCommentData('Body', tags: ['one', 'two'])))
        ->toThrow(InvalidCommentMutationException::class, 'at most 1 tags');

    config()->set('comments.content.maximum_tags', 20);
    expect(fn () => $guard->create(new CreateCommentData('Body', tags: ['same', 'same'])))
        ->toThrow(InvalidCommentMutationException::class, 'must be distinct');

    expect(fn () => $guard->create(new CreateCommentData(
        'Body',
        metadata: ['invalid' => "\xB1\x31"],
    )))->toThrow(InvalidCommentMutationException::class, 'valid JSON data');

    config()->set('comments.content.maximum_bytes', 4);
    expect(fn () => $guard->create(new CreateCommentData('Body', metadata: ['key' => 'value'])))
        ->toThrow(InvalidCommentMutationException::class, 'exceeds the content byte limit');
});

it('enforces lifecycle tokens and terminal anonymization audit reasons', function (): void {
    $guard = app(CommentLifecycleGuard::class);

    expect(fn () => $guard->expectedRevision(0))
        ->toThrow(InvalidCommentLifecycleException::class, 'positive expected comment revision')
        ->and(fn () => $guard->idempotencyKey('not-a-uuid'))
        ->toThrow(InvalidCommentMutationException::class, 'must be valid UUIDs')
        ->and(fn () => $guard->anonymization(new AnonymizeCommentData(1, '   ')))
        ->toThrow(InvalidCommentLifecycleException::class, 'valid, non-blank UTF-8')
        ->and(fn () => $guard->anonymization(new AnonymizeCommentData(1, str_repeat('a', 2_001))))
        ->toThrow(InvalidCommentLifecycleException::class, 'at most 2000 characters');

    $uppercaseKey = Str::upper(Str::uuid()->toString());

    expect($guard->idempotencyKey($uppercaseKey))->toBe(Str::lower($uppercaseKey));
});

it('renders middleware exceptions as private JSON errors inside the cache boundary', function (): void {
    $middleware = new CommentsResponseCache(app(ExceptionHandler::class));
    $request = Request::create('/api/v1/discussions', 'POST');
    $response = $middleware->handle(
        $request,
        static function (Request $request): never {
            throw new RuntimeException('Deliberate transport failure.');
        },
    );

    expect($request->header('Accept'))->toBe('application/json')
        ->and($response->getStatusCode())->toBe(500)
        ->and($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

it('resolves target-bound reads and preserves management missing-target diagnostics', function (): void {
    $target = new TestCommentTarget(['name' => 'Read target']);
    $target->save();
    $actor = new CommentActorData('member', 'read-author');
    $comment = app(CreateCommentAction::class)->execute(
        $target,
        new CreateCommentData('Visible comment'),
        $actor,
    );
    $reads = app(CommentReadService::class);

    $resolved = $reads->find(
        $target,
        $comment,
        CommentActorData::anonymous(),
        CommentAudience::Public,
    );

    expect($resolved->is($comment))->toBeTrue();

    $target->delete();

    expect(fn () => $reads->resolveById(
        $comment->id,
        CommentActorData::system(),
        CommentAudience::Management,
        CommentAbility::View,
    ))->toThrow(CommentTargetNotFoundException::class)
        ->and(fn () => $reads->resolveById(
            $comment->id,
            CommentActorData::anonymous(),
            CommentAudience::Public,
            CommentAbility::View,
        ))->toThrow(ModelNotFoundException::class);
});

it('returns stable machine-readable reconciliation failures', function (): void {
    expect(Artisan::call('nvl:comments:reconcile', ['--format' => 'yaml']))->toBe(1)
        ->and(Artisan::output())->toContain('must be table or json');

    foreach (['article', ':identifier', 'article:', 'missing:identifier'] as $selector) {
        $exitCode = Artisan::call('nvl:comments:reconcile', [
            '--format' => 'json',
            '--target' => $selector,
        ]);
        $output = commentsCoverageJsonOutput();

        expect($exitCode)->toBe(1)
            ->and($output)->toHaveKey('error')
            ->and($output['error'])->toBeString();
    }

    $missingTargetExitCode = Artisan::call('nvl:comments:reconcile', [
        '--format' => 'json',
        '--target' => 'article:'.Str::uuid()->toString(),
    ]);
    $missingTargetOutput = commentsCoverageJsonOutput();

    expect($missingTargetExitCode)->toBe(1)
        ->and($missingTargetOutput)->toHaveKey('error');
});

it('rejects unsafe reconciliation repair and chunk options with JSON envelopes', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');

    $productionExitCode = Artisan::call('nvl:comments:reconcile', [
        '--repair' => true,
        '--format' => 'json',
    ]);
    $productionOutput = commentsCoverageJsonOutput();

    expect($productionExitCode)->toBe(1)
        ->and($productionOutput)->toBe([
            'error' => 'The --force option is required to repair comments in production.',
        ]);

    foreach ([0, '1.5', 10_001] as $chunk) {
        $exitCode = Artisan::call('nvl:comments:reconcile', [
            '--format' => 'json',
            '--chunk' => $chunk,
        ]);
        $output = commentsCoverageJsonOutput();

        expect($exitCode)->toBe(1)
            ->and($output)->toBe([
                'error' => 'The --chunk option must be an integer between 1 and 10000.',
            ]);
    }
});

it('rejects malformed production configuration in strict diagnostics', function (
    string $key,
    mixed $value,
): void {
    config()->set($key, $value);

    [$exitCode, $report] = commentsCoverageDoctor();

    expect($exitCode)->toBe(1)
        ->and($report['configuration.values'])->toBeFalse()
        ->and($report['healthy'])->toBeFalse();
})->with([
    'string boolean' => ['comments.anonymous.enabled', 'false'],
    'string byte limit' => ['comments.content.maximum_bytes', '20000'],
    'negative cache lifetime' => ['comments.cache.public_max_age', -1],
    'mutable vendor migration target' => ['comments.tables.comments', 'tenant_comments'],
    'unsafe route prefix' => ['comments.routes.public.prefix', '../private-comments'],
    'duplicate formats' => ['comments.content.allowed_formats', ['plain', 'plain']],
    'unknown format' => ['comments.content.allowed_formats', ['plain', 'html']],
    'associative reaction list' => ['comments.reactions.allowed', ['positive' => 'like']],
    'unknown moderation status' => ['comments.moderation.new_status', 'APPROVED'],
]);

it('renders the complete human-readable Doctor report', function (): void {
    $exitCode = Artisan::call('nvl:comments:doctor', [
        '--strict' => true,
        '--format' => 'text',
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain(
            'configuration.values',
            'table.comments',
            'mutation_lock.ready',
            'healthy',
            'true',
        );
});
