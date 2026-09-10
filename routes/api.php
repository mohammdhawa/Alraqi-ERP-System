<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| This file is intentionally EMPTY of route definitions.
|
| Every endpoint in this system belongs to a module and is declared in that
| module's own routes.php, which ModuleServiceProvider auto-discovers and mounts
| at /api/{module}. Adding a route here instead would place it outside any
| module, outside the permission catalogue, and outside the conventions the rest
| of the API is held to.
|
| It previously held Laravel's scaffold route:
|
|     Route::get('/user', fn (Request $request) => $request->user())
|         ->middleware('auth:sanctum');
|
| which returned a RAW Eloquent model outside the envelope (C15, C18), carried no
| permission and no comment naming its authorization model (C7), and exposed the
| users table's shape directly to any authenticated caller. It was scaffolding
| that shipped, not a decision. GET /api/auth/me is the real endpoint — it
| returns an AuthResource in the standard envelope.
|
| Laravel's withRouting() still needs this file to exist.
|
*/
