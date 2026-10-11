<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures idempotency for checkout mutations through cached responses, atomic locking, and payload fingerprinting.
 *
 * Implements dual-anchor synchronization between HTTP 'Idempotency-Key' and domain 'checkout_token':
 * 1. Synchronizes cache and atomic distributed lock across both identifiers to prevent DB constraint clashes.
 * 2. Compares cryptographic SHA-256 hash of incoming payload to detect Payload Mismatch vulnerabilities.
 * 3. Acquires an atomic distributed lock (Mutex) to block concurrent in-flight requests.
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
        $headerKey = $request->header('Idempotency-Key');
        $bodyToken = $request->input('checkout_token');

        $headerKey = is_string($headerKey) && trim($headerKey) !== '' ? trim($headerKey) : null;
        $bodyToken = is_string($bodyToken) && trim($bodyToken) !== '' ? trim($bodyToken) : null;

        // If no idempotency token is detectable, proceed normally
        if ($headerKey === null && $bodyToken === null) {
            return $next($request);
        }

        $canonicalKey = $headerKey ?? $bodyToken;
        $requestPayloadHash = hash('sha256', (string) $request->getContent());

        $cacheKeys = array_values(array_unique(array_filter([
            $headerKey !== null ? "idempotency:key:" . $headerKey : null,
            $bodyToken !== null ? "idempotency:token:" . $bodyToken : null,
            "idempotency:" . $canonicalKey,
        ])));

        $lockKeys = array_values(array_unique(array_filter([
            $headerKey !== null ? "lock:idempotency:" . $headerKey : null,
            $bodyToken !== null ? "lock:idempotency:" . $bodyToken : null,
            "lock:idempotency:" . $canonicalKey,
        ])));

        // Deterministic sorting mathematically eliminates Distributed Deadlocks across concurrent workers
        sort($lockKeys);

        // 1. Inspect cache across all synchronized idempotency keys
        /** @var array{status: int, content: string, headers: array<string, array<int, string>>, payload_hash?: string}|null $cached */
        $cached = null;
        foreach ($cacheKeys as $key) {
            $cached = Cache::get($key);
            if ($cached !== null) {
                break;
            }
        }

        if ($cached !== null) {
            // IETF RFC Validation: Reject if the same Idempotency-Key is reused with an altered payload
            if (isset($cached['payload_hash']) && $cached['payload_hash'] !== $requestPayloadHash) {
                return response()->json([
                    'error' => 'idempotency_payload_mismatch',
                    'message' => 'L\'Idempotency-Key specificata è già stata utilizzata per una richiesta con payload differente. Per modificare i parametri dell\'ordine genera una nuova chiave univoca.',
                    'idempotency_key' => $canonicalKey,
                ], 422);
            }

            return response($cached['content'], $cached['status'])
                ->withHeaders(array_merge($cached['headers'], ['X-Cache' => 'HIT']));
        }

        // 2. Atomic Lock anchored on all keys to prevent in-flight concurrent race conditions
        /** @var array<int, \Illuminate\Contracts\Cache\Lock> $acquiredLocks */
        $acquiredLocks = [];

        foreach ($lockKeys as $lockKey) {
            $lock = Cache::lock($lockKey, 15);

            if (! $lock->get()) {
                foreach ($acquiredLocks as $acquired) {
                    $acquired->release();
                }

                return response()->json([
                    'error' => 'request_in_flight',
                    'message' => 'Una richiesta di checkout con questa Idempotency-Key è attualmente in fase di elaborazione. Riprova tra pochi istanti.',
                    'idempotency_key' => $canonicalKey,
                ], 409);
            }

            $acquiredLocks[] = $lock;
        }

        try {
            $response = $next($request);

            // Cache successful checkout creations (201 Created) for 24 hours across all bound keys
            if ($response instanceof JsonResponse && $response->getStatusCode() === 201) {
                $cachePayload = [
                    'status' => $response->getStatusCode(),
                    'content' => (string) $response->getContent(),
                    'headers' => $response->headers->all(),
                    'payload_hash' => $requestPayloadHash,
                ];

                foreach ($cacheKeys as $key) {
                    Cache::put($key, $cachePayload, 86400);
                }

                $response->headers->set('X-Cache', 'MISS');
            }

            return $response;
        } finally {
            foreach ($acquiredLocks as $acquired) {
                $acquired->release();
            }
        }
    }
}
