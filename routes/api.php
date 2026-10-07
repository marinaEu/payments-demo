<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\IncrementNumberController;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/increment/{number?}', IncrementNumberController::class);

Route::post('/checkout', CheckoutController::class)
    ->middleware(IdempotencyMiddleware::class);
