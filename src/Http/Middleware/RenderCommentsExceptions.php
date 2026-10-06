<?php

declare(strict_types=1);

namespace Nvl\Comments\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Http\PackageExceptionPayload;
use Symfony\Component\HttpFoundation\Response;

/** Preserves the native Comments envelope on already enabled package routes. */
final readonly class RenderCommentsExceptions
{
    public function __construct(private PackageExceptionPayload $payload) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (RespondableException $exception) {
            if ($exception->package() !== 'comments') {
                throw $exception;
            }

            return $this->response($exception);
        }

        if (($response instanceof JsonResponse || $response instanceof LaravelResponse)
            && $response->exception instanceof RespondableException
            && $response->exception->package() === 'comments') {
            return $this->response($response->exception);
        }

        return $response;
    }

    /** Reconstruct the established route envelope from safe metadata. */
    private function response(RespondableException $exception): JsonResponse
    {
        $payload = $this->payload->for($exception);

        return new JsonResponse(['message' => $payload['message'], 'code' => $payload['code']], $exception->suggestedStatus(), $this->payload->headers($exception));
    }
}
