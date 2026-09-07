<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API callers get JSON errors whether or not they send an Accept
        // header: no guest redirect for api/*, and the Filament login for
        // everything else (the framework default is a `login` route this
        // app does not have).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('filament.admin.auth.login'));

        // Sanctum's ability checks are opt-in aliases. `ability:search:read`
        // rejects, with 403, a token that was issued without that ability -
        // the mobile web build keeps its token in localStorage, so a stolen
        // token must be bounded by what it was minted for.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
