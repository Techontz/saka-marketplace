<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 13's base controller is empty by design. AuthorizesRequests is added
 * here so every controller can call $this->authorize(); policies are the only
 * place record-level permission is decided.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
