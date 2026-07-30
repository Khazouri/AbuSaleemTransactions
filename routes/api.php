<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\ScreenController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| API routes
|------------------------------------------------------------------------------
| Everything here is automatically prefixed with /api and is stateless — the
| Vue SPA is the only client. Authentication is by Sanctum bearer token.
|
| CORS (which origins may call these routes) is configured in config/cors.php
| and currently allows the Vite dev server at localhost:5173.
*/

/**
 * Connectivity check — no auth, no database. Used to confirm the SPA can reach
 * Laravel through Homestead's nginx.
 */
Route::get('/ping', fn () => response()->json([
    'message' => 'pong',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

Route::prefix('auth')->group(function () {
    /*
     * Public: this is where a user gets their token, so it can't require one.
     *
     * throttle:6,1 caps it at 6 attempts per minute per IP. Login is the one
     * endpoint worth guessing at, and without a limit an attacker could try
     * passwords as fast as the server responds. Exceeding it returns 429,
     * which the login form reports as "too many attempts".
     */
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1');

    /*
     * Protected: auth:sanctum rejects anything without a valid bearer token
     * with a 401, which the SPA's axios interceptor turns into a logout.
     */
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

/*
 * Signed-in application routes.
 */
Route::middleware('auth:sanctum')->group(function () {
    // Sidebar navigation, filtered to the screens this user may view.
    Route::get('/screens', [ScreenController::class, 'index']);

    /*
     * Departments (الإدارات).
     *
     * No `show` route — the SPA already holds the full list from index(), so a
     * single-department endpoint would be dead weight.
     *
     * toggle-active is declared BEFORE the resource routes. Registration order
     * matters: apiResource creates DELETE/PUT on departments/{department}, and
     * a later, more specific path would still work here, but keeping explicit
     * routes above the resource is the habit that avoids surprises once
     * wildcards get involved.
     */
    Route::patch('departments/{department}/toggle-active', [DepartmentController::class, 'toggleActive']);
    Route::apiResource('departments', DepartmentController::class)->except(['show']);
});
