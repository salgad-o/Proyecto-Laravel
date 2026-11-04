@extends('layouts.app')

@section('title', 'Seguridad · Mi perfil')

@section('content')
    @include('perfil._cabecera', ['subtitulo' => 'Protege el acceso a tu cuenta.'])

    <div class="perfil">
        {{-- Cambio de contraseña. Los campos de contraseña NUNCA se rellenan con old(). --}}
        <section class="perfil-tarjeta" aria-labelledby="perfil-contrasena-titulo">
            <h2 id="perfil-contrasena-titulo" class="perfil-tarjeta-titulo">Contraseña</h2>
            <p class="perfil-tarjeta-texto">Usa 12 o más caracteres con mayúsculas, minúsculas, números y símbolos.</p>
            <p class="perfil-fila-ayuda">Al cambiarla se cerrará tu sesión en los demás dispositivos.</p>

            <form method="POST" action="{{ route('perfil.contrasena') }}" class="perfil-formulario-seguridad">
                @csrf
                @method('PUT')

                <div class="campo">
                    <label for="current_password">Contraseña actual</label>
                    <input id="current_password" type="password" name="current_password" required autocomplete="current-password" @error('current_password') aria-invalid="true" aria-describedby="error-current_password" @enderror>
                    @error('current_password') <p id="error-current_password" class="error">{{ $message }}</p> @enderror
                </div>

                <div class="campo">
                    <label for="password">Nueva contraseña</label>
                    <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password" aria-describedby="ayuda-password @error('password') error-password @enderror" @error('password') aria-invalid="true" @enderror>
                    <p id="ayuda-password" class="perfil-fila-ayuda">Mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos. Debe ser distinta de la actual.</p>
                    @error('password') <p id="error-password" class="error">{{ $message }}</p> @enderror
                </div>

                <div class="campo">
                    <label for="password_confirmation">Confirma la nueva contraseña</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                </div>

                <button type="submit" class="boton">Cambiar contraseña</button>
            </form>
        </section>

        {{-- NP-36 paso 4: tarjeta "Sesiones" (sesiones abiertas y cerrar las demás). --}}
    </div>
@endsection
