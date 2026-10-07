<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures idempotency for checkout mutations through request caching.
 */
class IdempotencyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            $idempotencyKey = $request->input('checkout_token');
        }

        // If no idempotency token is detectable, proceed normally
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return $next($request);
        }

        $cacheKey = "idempotency:" . trim($idempotencyKey);

        /** @var array{status: int, content: string, headers: array<string, array<int, string>>}|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return response($cached['content'], $cached['status'])
                ->withHeaders(array_merge($cached['headers'], ['X-Cache' => 'HIT']));
        }

        $response = $next($request);

        // Cache successful checkout creations (201 Created) for 24 hours
        if ($response instanceof JsonResponse && $response->getStatusCode() === 201) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'content' => (string) $response->getContent(),
                'headers' => $response->headers->all(),
            ], 86400);

            $response->headers->set('X-Cache', 'MISS');
        }

        return $response;
    }
}
