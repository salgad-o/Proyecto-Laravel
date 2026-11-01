<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuarioInactivoTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE = 'Tu cuenta fue desactivada. Si crees que es un error, contacta al administrador.';

    // ---------- Login ----------

    public function test_un_usuario_inactivo_no_inicia_sesion_y_recibe_el_error_generico(): void
    {
        $activo = User::factory()->create();
        $inactivo = User::factory()->inactivo()->create();

        $errorClaveIncorrecta = $this->post(route('login.store'), [
            'email' => $activo->email,
            'password' => 'incorrecta',
        ])->assertSessionHasErrors('email')->getSession()->get('errors')->first('email');

        $errorInactivo = $this->post(route('login.store'), [
            'email' => $inactivo->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email')->getSession()->get('errors')->first('email');

        $this->assertSame($errorClaveIncorrecta, $errorInactivo);
        $this->assertGuest();
    }

    public function test_los_intentos_de_un_usuario_inactivo_cuentan_para_el_limite(): void
    {
        $inactivo = User::factory()->inactivo()->create();
        $datos = ['email' => $inactivo->email, 'password' => 'password'];

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('login.store'), $datos)->assertStatus(302);
        }

        $this->post(route('login.store'), $datos)->assertStatus(429);
    }

    public function test_un_usuario_activo_no_es_expulsado(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertAuthenticated();
    }

    // ---------- Sesión abierta ----------

    public function test_un_usuario_desactivado_con_sesion_abierta_es_expulsado(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();

        $usuario->is_active = false;
        $usuario->save();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', self::MENSAJE);

        $this->assertGuest();
    }

    public function test_la_sesion_expulsada_termina_en_el_login_con_el_mensaje_sin_bucles(): void
    {
        $inactivo = User::factory()->inactivo()->create();

        $this->actingAs($inactivo)
            ->followingRedirects()
            ->get(route('login'))
            ->assertOk()
            ->assertSee(self::MENSAJE);

        $this->assertGuest();
    }

    public function test_logout_de_un_usuario_inactivo_termina_en_el_login(): void
    {
        $inactivo = User::factory()->inactivo()->create();

        $this->actingAs($inactivo)
            ->followingRedirects()
            ->post(route('logout'))
            ->assertOk()
            ->assertSee(self::MENSAJE);

        $this->assertGuest();
    }

    public function test_una_pagina_publica_tambien_expulsa_al_usuario_inactivo(): void
    {
        $inactivo = User::factory()->inactivo()->create();

        $this->actingAs($inactivo)
            ->followingRedirects()
            ->get(route('home'))
            ->assertOk()
            ->assertSee(self::MENSAJE);

        $this->assertGuest();
    }
}
