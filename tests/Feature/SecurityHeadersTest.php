<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;
    public function test_las_paginas_envian_las_cabeceras_de_seguridad(): void
    {
        $this->get('/')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $csp = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_las_paginas_de_error_tambien_envian_cabeceras_de_seguridad(): void
    {
        // 404 por route model binding: la ruta existe, el modelo no.
        $this->get('/noticias/999999')
            ->assertStatus(404)
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_csp_sin_origen_vite_fuera_de_local(): void
    {
        // En testing (APP_ENV=testing) no se agrega el origen de Vite.
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString(':5173', $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }
}
