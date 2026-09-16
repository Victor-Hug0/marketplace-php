<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'abilities:access']);
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware(['auth:sanctum', 'abilities:refresh']);
    });

    Route::prefix('users')->group(function () {
        Route::get('me', [UserController::class, 'me'])->middleware(['auth:sanctum', 'abilities:access']);
    });
});
