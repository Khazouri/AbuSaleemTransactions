<?php

use Illuminate\Support\Facades\Route;

/*
 * The unused Laravel Blade welcome page (the real UI is the SPA in frontend/).
 *
 * Declared with Route::view rather than a Closure deliberately: a Closure route
 * cannot be serialized, so its presence makes `php artisan route:cache` — and
 * therefore `optimize` — fail with "Unable to prepare route [/] for
 * serialization", which is exactly what the maintenance console offers on a
 * shared host where those caches matter most.
 */
Route::view('/', 'welcome');
