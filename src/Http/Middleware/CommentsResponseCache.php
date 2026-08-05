<?php

declare(strict_types=1);

namespace Nvl\Comments\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Nvl\Comments\Support\CommentsRouteConfiguration;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Enforces private caching for viewer-aware, mutation, error, and asset responses.
 */
final readonly class CommentsResponseCache
{
    public function __construct(private ExceptionHandler $exceptions) {}

    /**
     * Render route exceptions inside the cache boundary and protect every non-public response.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
            $response = $this->exceptions->render($request, $exception);
        }

        if ($this->isSuccessfulPublicRead($request, $response)) {
            return $response;
        }

        $response->headers->set(
            'Cache-Control',
            'private, no-store, max-age=0',
        );

        return $response;
    }

    /**
     * Preserve shared caching only for successful viewer-independent public reads.
     */
    private function isSuccessfulPublicRead(
        Request $request,
        Response $response,
    ): bool {
        $route = $request->route();

        if (! $route instanceof Route
            || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || ! $response->isSuccessful()) {
            return false;
        }

        $publicName = CommentsRouteConfiguration::name('public');

        return in_array(
            $route->getName(),
            [
                "{$publicName}index",
                "{$publicName}show",
                "{$publicName}attachments.index",
            ],
            true,
        );
    }
}
