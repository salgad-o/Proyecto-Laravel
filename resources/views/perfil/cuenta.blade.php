@extends('layouts.app')

@section('title', 'Cuenta · Mi perfil')

@section('content')
    @include('perfil._cabecera', ['subtitulo' => 'Decide qué pasa con tu cuenta.'])

    <div class="perfil">
        {{-- Zona de peligro. La cuenta no se borra (NP-14): solo se desactiva (is_active = false). --}}
        <section class="perfil-tarjeta perfil-peligro" aria-labelledby="perfil-desactivar-titulo">
            <h2 id="perfil-desactivar-titulo" class="perfil-tarjeta-titulo">Desactivar mi cuenta</h2>

            <ul class="perfil-peligro-lista">
                <li>No podrás iniciar sesión y se cerrarán todas tus sesiones.</li>
                <li>Tu cuenta no se borra: tus comentarios y noticias siguen visibles con tu nombre.</li>
                <li>Para reactivarla o solicitar la supresión de tus datos, escríbenos por <a href="{{ route('contact') }}">Contacto</a>. Más información en la <a href="{{ route('legal.privacidad') }}">Política de privacidad</a>.</li>
            </ul>

            @if ($unicoAdmin)
                {{-- Sin formulario: el sitio nunca debe quedarse sin administradores activos. --}}
                <p class="perfil-aviso">Eres el único administrador activo, así que no puedes desactivar tu cuenta. Asigna el rol de administrador a otra persona en <a href="{{ route('usuarios.index') }}">Usuarios</a> y vuelve a intentarlo.</p>
                @error('current_password', 'desactivar') <p class="error" role="alert">{{ $message }}</p> @enderror
            @else
                <form method="POST" action="{{ route('perfil.desactivar') }}" class="perfil-formulario-seguridad">
                    @csrf

                    <div class="campo">
                        <label for="desactivar_current_password">Contraseña actual</label>
                        <input id="desactivar_current_password" type="password" name="current_password" required autocomplete="current-password" aria-describedby="ayuda-desactivar @error('current_password', 'desactivar') error-desactivar_current_password @enderror" @error('current_password', 'desactivar') aria-invalid="true" @enderror>
                        <p id="ayuda-desactivar" class="perfil-fila-ayuda">Escríbela para confirmar que eres tú.</p>
                        @error('current_password', 'desactivar') <p id="error-desactivar_current_password" class="error">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit" class="boton boton-peligro" data-confirm="¿Seguro que quieres desactivar tu cuenta? Se cerrarán todas tus sesiones.">Desactivar mi cuenta</button>
                </form>
            @endif
        </section>
    </div>
@endsection
