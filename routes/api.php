<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Support\Facades\Route;

Route::post('/checkout', CheckoutController::class)
    ->middleware(IdempotencyMiddleware::class);
