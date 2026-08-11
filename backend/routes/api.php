<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API entry point
|--------------------------------------------------------------------------
|
| URI-path versioning. This file ONLY mounts versions — every route lives in
| its own version file with its own controller namespace and its own Resources,
| so a future v2 can reshape responses without touching v1.
|
| Additive changes (a new optional field, a new endpoint) ship inside v1.
| Breaking changes (removed/renamed field, changed type or semantics) require
| a new version file.
|
*/

Route::prefix('v1')
    ->as('api.v1.')
    ->group(base_path('routes/api_v1.php'));
