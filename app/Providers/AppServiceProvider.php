<?php

namespace App\Providers;

use App\Contracts\LectorBuzon;
use App\Services\Correo\LectorBuzonWebklex;
use App\Support\ConsentimientoCookies;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // NP-46 (D8): el comando mail:receive depende de la interfaz; los tests
        // la sustituyen por una implementación falsa y nunca se conectan a Gmail.
        $this->app->bind(LectorBuzon::class, LectorBuzonWebklex::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Política única de contraseñas: registro, restablecimiento y futuros cambios.
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());

        // El layout recibe la elección de cookies ya validada (null si no hay una válida).
        // Toda la lógica de lectura vive en ConsentimientoCookies.
        View::composer('layouts.app', function ($view) {
            $view->with('consentimientoCookies', ConsentimientoCookies::actual(request()));
        });
    }
}
