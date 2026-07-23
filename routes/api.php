<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json([
    'message' => 'pong',
    'app' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
