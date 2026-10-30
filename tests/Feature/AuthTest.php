<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE_VALIDA = 'Segura#Clave2026';

    private function datosRegistro(array $cambios = []): array
    {
        return array_merge([
            'name' => 'Jugador Nuevo',
            'email' => 'nuevo@nexus.test',
            'password' => self::CLAVE_VALIDA,
            'password_confirmation' => self::CLAVE_VALIDA,
        ], $cambios);
    }

    // ---------- Registro ----------

    public function test_la_pagina_de_registro_carga(): void
    {
        $this->get(route('register'))->assertOk();
    }

    public function test_un_visitante_se_registra_como_lector(): void
    {
        $respuesta = $this->post(route('register.store'), $this->datosRegistro());

        $respuesta->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $usuario = User::where('email', 'nuevo@nexus.test')->firstOrFail();
        $this->assertTrue($usuario->hasRole('lector'));
        $this->assertNotSame(self::CLAVE_VALIDA, $usuario->password, 'La contraseña debe guardarse con hash.');
    }

    public function test_el_correo_se_guarda_en_minusculas(): void
    {
        $this->post(route('register.store'), $this->datosRegistro(['email' => 'Nuevo@Nexus.TEST']));

        $this->assertDatabaseHas('users', ['email' => 'nuevo@nexus.test']);
    }

    public function test_el_registro_rechaza_una_contrasena_debil(): void
    {
        $respuesta = $this->post(route('register.store'), $this->datosRegistro([
            'password' => 'debil123',
            'password_confirmation' => 'debil123',
        ]));

        $respuesta->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'nuevo@nexus.test']);
    }

    public function test_el_registro_rechaza_un_correo_repetido(): void
    {
        User::factory()->create(['email' => 'nuevo@nexus.test']);

        $this->post(route('register.store'), $this->datosRegistro())
            ->assertSessionHasErrors('email');
    }

    public function test_el_registro_ignora_un_role_id_enviado_en_el_formulario(): void
    {
        $idAdmin = Role::where('name', 'admin')->value('id');

        $this->post(route('register.store'), $this->datosRegistro(['role_id' => $idAdmin]));

        $usuario = User::where('email', 'nuevo@nexus.test')->firstOrFail();
        $this->assertFalse($usuario->isAdmin(), 'Escalada de privilegios: el registro no puede asignar el rol admin.');
    }

    // ---------- Login ----------

    public function test_un_usuario_inicia_sesion_con_credenciales_correctas(): void
    {
        $usuario = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $usuario->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_el_login_regenera_el_id_de_sesion(): void
    {
        $usuario = User::factory()->create();

        $this->get(route('login'));
        $idAntes = session()->getId();

        $this->post(route('login.store'), [
            'email' => $usuario->email,
            'password' => 'password',
        ]);

        $this->assertNotSame($idAntes, session()->getId(), 'El ID de sesión debe cambiar al iniciar sesión (fijación de sesión).');
    }

    public function test_el_login_rechaza_una_contrasena_incorrecta(): void
    {
        $usuario = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $usuario->email,
            'password' => 'incorrecta',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_el_login_se_bloquea_tras_cinco_intentos_por_minuto(): void
    {
        $usuario = User::factory()->create();
        $datos = ['email' => $usuario->email, 'password' => 'incorrecta'];

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('login.store'), $datos)->assertStatus(302);
        }

        $this->post(route('login.store'), $datos)->assertStatus(429);
    }

    // ---------- Sesión ----------

    public function test_un_usuario_cierra_sesion(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_el_dashboard_exige_iniciar_sesion(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_un_usuario_autenticado_no_ve_el_formulario_de_login(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get(route('login'))->assertRedirect();
    }
}
