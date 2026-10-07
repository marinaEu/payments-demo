<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Centralized handler for database uniqueness violations (Idempotency Level 2)
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            $token = $request->input('checkout_token') ?? $request->header('Idempotency-Key');

            return response()->json([
                'error' => 'duplicate_order',
                'message' => 'Un ordine con questo token di checkout è già in fase di elaborazione o precedentemente completato.',
                'idempotency_key' => $token,
            ], 409);
        });
    })->create();
