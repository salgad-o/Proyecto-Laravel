<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            report($e);
            $response = app('exception')->render($request, $e);
        }

        return $this->addHeaders($response);
    }

    private function addHeaders(Response $response): Response
    {
        $viteSrc = '';
        $viteConnect = '';

        if (app()->environment('local') && Vite::isRunningHot()) {
            $viteOrigin = rtrim(trim(file_get_contents(Vite::hotFile())), '/');
            $viteWs = preg_replace('#^http#', 'ws', $viteOrigin);

            $viteSrc = ' ' . $viteOrigin;
            $viteConnect = ' ' . $viteOrigin . ' ' . $viteWs;
        }

        $csp = "default-src 'self'; "
             . "script-src 'self'" . $viteSrc . "; "
             . "style-src 'self'" . $viteSrc . "; "
             . "connect-src 'self'" . $viteConnect . "; "
             . "img-src 'self' data:; "
             . "object-src 'none'; "
             . "base-uri 'self'; "
             . "form-action 'self'; "
             . "frame-ancestors 'none'";

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        header_remove('X-Powered-By');

        return $response;
    }
}
