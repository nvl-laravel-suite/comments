<?php

declare(strict_types=1);

namespace Nvl\Comments\Services;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaAssetService;
use Nvl\Media\Services\MediaDiskGateway;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Serves a live attachment after the signed association route has authorized delivery.
 */
final readonly class CommentAttachmentAssetResponder
{
    public function __construct(
        private MediaAssetService $assets,
        private MediaDiskGateway $disks,
    ) {}

    /**
     * Serve one available original or pre-generated variation with private caching.
     */
    public function serve(
        Request $request,
        Media $media,
        ?string $variationLabel = null,
    ): BinaryFileResponse|StreamedResponse|Response {
        try {
            $resolved = $this->assets->resolve($media, $variationLabel);
        } catch (RuntimeException) {
            abort(404);
        }

        if ($this->matchesEtag($request, $resolved['etag'])) {
            return response('', 304)->withHeaders([
                'ETag' => "\"{$resolved['etag']}\"",
                'Cache-Control' => 'private, no-store, max-age=0',
            ]);
        }

        $headers = [
            'Content-Type' => $resolved['mime_type'],
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => $this->contentDisposition($media->filename),
            'ETag' => "\"{$resolved['etag']}\"",
        ];

        if ($this->disks->isLocal($resolved['disk'])) {
            $absolutePath = $this->disks->localPath(
                $resolved['disk'],
                $resolved['path'],
            );

            abort_unless(file_exists($absolutePath), 404);

            $response = response()->file($absolutePath, $headers);
            $response->headers->set(
                'Cache-Control',
                'private, no-store, max-age=0',
            );

            return $response;
        }

        try {
            $contentLength = $this->disks->size(
                $resolved['disk'],
                $resolved['path'],
            );
        } catch (Throwable) {
            $contentLength = null;
        }

        $range = $this->resolveRange($request, $contentLength);
        $status = $range === null ? 200 : 206;

        if ($contentLength !== null) {
            $headers['Content-Length'] = (string) ($range === null
                ? $contentLength
                : $range['end'] - $range['start'] + 1);
        }

        if ($range !== null && $contentLength !== null) {
            $headers['Content-Range'] = "bytes {$range['start']}-{$range['end']}/{$contentLength}";
        }

        if ($request->isMethod('HEAD')) {
            return response('', $status, $headers);
        }

        $stream = $this->disks->readStream(
            $resolved['disk'],
            $resolved['path'],
        );

        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($range, $stream): void {
            $remaining = $range === null
                ? null
                : $range['end'] - $range['start'] + 1;

            if ($range !== null && $range['start'] > 0
                && @fseek($stream, $range['start']) !== 0) {
                $discard = $range['start'];

                while ($discard > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(8192, $discard));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $discard -= strlen($chunk);
                }
            }

            while (! feof($stream) && ($remaining === null || $remaining > 0)) {
                $chunk = fread(
                    $stream,
                    $remaining === null ? 8192 : min(8192, $remaining),
                );

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                flush();

                if ($remaining !== null) {
                    $remaining -= strlen($chunk);
                }
            }

            fclose($stream);
        }, $status, $headers);
    }

    /**
     * Parse one bounded single byte range.
     *
     * @return array{start: int, end: int}|null
     */
    private function resolveRange(Request $request, ?int $size): ?array
    {
        $header = $request->header('Range');

        if (! is_string($header) || trim($header) === '' || $size === null) {
            return null;
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            abort(416, headers: ['Content-Range' => "bytes */{$size}"]);
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];

            if ($suffixLength < 1) {
                abort(416, headers: ['Content-Range' => "bytes */{$size}"]);
            }

            return [
                'start' => max(0, $size - $suffixLength),
                'end' => $size - 1,
            ];
        }

        $start = (int) $matches[1];
        $end = $matches[2] === ''
            ? $size - 1
            : min((int) $matches[2], $size - 1);

        if ($start >= $size || $end < $start) {
            abort(416, headers: ['Content-Range' => "bytes */{$size}"]);
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Build a safe inline filename header.
     */
    private function contentDisposition(string $filename): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename)
            ?: 'attachment';

        return "inline; filename=\"{$fallback}\"; filename*=UTF-8''".rawurlencode($filename);
    }

    /**
     * Determine whether the current request already holds the asset ETag.
     */
    private function matchesEtag(Request $request, string $etag): bool
    {
        $header = $request->header('If-None-Match');

        if (! is_string($header) || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if (hash_equals("\"{$etag}\"", $candidate)) {
                return true;
            }
        }

        return false;
    }
}
