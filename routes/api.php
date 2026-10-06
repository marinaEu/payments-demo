<?php

use App\Http\Controllers\IncrementNumberController;
use Illuminate\Support\Facades\Route;

Route::get('/increment/{number?}', IncrementNumberController::class);
