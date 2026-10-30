<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $isLocal = app()->environment('local');

        $csp = "default-src 'self'; "
             . "script-src 'self'" . ($isLocal ? " http://localhost:5173 http://[::1]:5173" : "") . "; "
             . "style-src 'self'" . ($isLocal ? " http://localhost:5173 http://[::1]:5173" : "") . "; "
             . "connect-src 'self'" . ($isLocal ? " http://localhost:5173 http://[::1]:5173 ws://localhost:5173 ws://[::1]:5173" : "") . "; "
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
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
