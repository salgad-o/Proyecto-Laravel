<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        // Middleware GLOBAL (no solo del grupo 'web'): así sus cabeceras llegan a
        // cualquier respuesta, también al 404 de una URL que no coincide con ninguna
        // ruta (ej. /sitemap.xml). Ese 404 nunca pasa por el grupo 'web', y por eso
        // ZAP encontró ahí X-Powered-By y la falta de CSP (hallazgos H-04 y H-06).
        $middleware->prepend(SecurityHeaders::class);

        // Al final del grupo 'web': necesita que la sesión ya haya arrancado.
        $middleware->appendToGroup('web', EnsureUserIsActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
