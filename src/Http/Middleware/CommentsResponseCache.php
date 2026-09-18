<?php

declare(strict_types=1);

namespace Nvl\Comments\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Nvl\Comments\Support\CommentsRouteConfiguration;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Enforces private caching for viewer-aware, mutation, error, and asset responses.
 */
final readonly class CommentsResponseCache
{
    public function __construct(
        private ExceptionHandler $exceptions,
        private ?TenantContext $context = null,
    ) {}

    /**
     * Render route exceptions inside the cache boundary and protect every non-public response.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $snapshot = $this->context?->snapshot();
        $request->headers->set(
            'X-Nvl-Comments-Tenant',
            hash('sha256', $this->tenantPartition($snapshot)),
        );
        $site = $this->site($request->attributes->get('site'));
        $request->headers->set(
            'X-Nvl-Comments-Site',
            hash('sha256', $request->getHost().'\0'.$site),
        );

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
            $response = $this->exceptions->render($request, $exception);
        }

        if ($this->isSuccessfulPublicRead($request, $response)) {
            $route = $request->route();
            $partition = hash('sha256', json_encode([
                'tenant' => $this->tenantPartition($this->context?->snapshot()),
                'site' => $site !== '' ? $site : $this->site($request->header('X-Site', '')),
                'host' => $request->getHost(),
                'route' => $route instanceof Route ? $route->getName() : null,
                'parameters' => $route instanceof Route ? $route->parameters() : [],
                'body' => $response->getContent(),
            ], JSON_THROW_ON_ERROR));
            $response->setEtag($partition);
            $response->setVary(['Host', 'X-Nvl-Comments-Site', 'X-Nvl-Comments-Tenant'], false);

            if ($request->isMethod('GET') && $response->isNotModified($request)) {
                return $response;
            }

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

    /** Resolve one stable cache partition from an optional tenant snapshot. */
    private function tenantPartition(?TenantContextSnapshot $snapshot): string
    {
        if ($snapshot === null) {
            return 'disabled';
        }

        return $snapshot->tenantId !== null
            ? $snapshot->tenantId->value
            : $snapshot->mode->value;
    }

    /** Normalize an untrusted route/header value without accepting compound input. */
    private function site(mixed $site): string
    {
        return is_string($site) || is_numeric($site) ? (string) $site : '';
    }
}
