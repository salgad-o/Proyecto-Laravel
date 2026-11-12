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

    public function test_las_paginas_envian_las_cabeceras_de_aislamiento_de_origen(): void
    {
        // Hallazgo H-05 (ZAP): sin COOP/CORP otra página puede abrir la nuestra
        // en una ventana y compartir proceso con ella (ataques tipo Spectre).
        $this->get('/')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
    }

    public function test_no_se_envia_la_cabecera_x_powered_by(): void
    {
        // Hallazgo H-04 (ZAP). Ojo: este test solo ve la respuesta de Symfony;
        // la cabecera que agrega PHP por fuera solo se comprueba con curl/ZAP.
        $this->get('/')->assertHeaderMissing('X-Powered-By');
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

    public function test_una_url_que_no_existe_tambien_envia_cabeceras_de_seguridad(): void
    {
        // Hallazgo H-06 (ZAP en /sitemap.xml): el 404 de una URL sin ruta no pasa
        // por el grupo 'web'. Por eso SecurityHeaders es un middleware global.
        $this->get('/sitemap.xml')
            ->assertNotFound()
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Permissions-Policy')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    public function test_csp_sin_origen_vite_fuera_de_local(): void
    {
        // En testing (APP_ENV=testing) no se agrega el origen de Vite.
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString(':5173', $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }
}
