@extends('layouts.app')

@section('title', 'Mi perfil')

@section('content')
    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">MI CUENTA</p>
            <h1 class="pagina-titulo">Mi perfil</h1>
            <p class="pagina-subtitulo">Revisa y actualiza los datos de tu cuenta.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    @php
        // Solo quienes publican tienen biografía (se muestra en sus noticias).
        $editaBio = $usuario->hasRole('admin', 'editor');
    @endphp

    <div class="perfil">
        {{-- Resumen de la cuenta. Solo fecha (sin hora): las horas tienen el desfase UTC de NP-40. --}}
        <section class="perfil-resumen" aria-labelledby="perfil-resumen-titulo">
            <h2 id="perfil-resumen-titulo" class="sr-only">Resumen de tu cuenta</h2>
            <span class="perfil-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($usuario->name, 0, 1)) }}</span>
            <div class="perfil-resumen-datos">
                <p class="perfil-nombre">{{ $usuario->name }}</p>
                <p class="perfil-correo">{{ $usuario->email }}</p>
                <p class="perfil-resumen-pie">
                    <span class="chip">{{ $usuario->role?->label ?? 'Sin rol' }}</span>
                    <span class="perfil-miembro">Miembro desde <time datetime="{{ $usuario->created_at->toDateString() }}">{{ $usuario->created_at->format('d/m/Y') }}</time></span>
                </p>
            </div>
        </section>

        <section class="perfil-seccion" aria-labelledby="perfil-datos-titulo">
            <h2 id="perfil-datos-titulo" class="perfil-seccion-titulo">Datos de la cuenta</h2>

            <form method="POST" action="{{ route('perfil.update') }}" class="formulario-tarjeta perfil-formulario">
                @csrf
                @method('PATCH')

                <div class="campo">
                    <label for="name">Nombre</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $usuario->name) }}" required maxlength="100" autocomplete="name" @error('name') aria-invalid="true" aria-describedby="error-name" @enderror>
                    @error('name') <p id="error-name" class="error">{{ $message }}</p> @enderror
                </div>

                <div class="campo">
                    <label for="email">Correo</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $usuario->email) }}" required maxlength="150" autocomplete="email" @error('email') aria-invalid="true" aria-describedby="error-email" @enderror>
                    @error('email') <p id="error-email" class="error">{{ $message }}</p> @enderror
                </div>

                @if ($editaBio)
                    <div class="campo">
                        <label for="bio">Biografía</label>
                        <textarea id="bio" name="bio" rows="4" maxlength="300" aria-describedby="ayuda-bio @error('bio') error-bio @enderror" data-contador="contador-bio" data-contador-aviso="aviso-bio" @error('bio') aria-invalid="true" @enderror>{{ old('bio', $usuario->bio) }}</textarea>
                        <p id="ayuda-bio" class="campo-ayuda">Se muestra bajo tu nombre en las noticias que publicas.</p>
                        @error('bio') <p id="error-bio" class="error">{{ $message }}</p> @enderror
                        <div class="campo-pie">
                            {{-- Oculto hasta que app.js lo active: sin JavaScript no se actualizaría. --}}
                            <span id="contador-bio" class="comentario-contador" hidden>{{ mb_strlen(old('bio', $usuario->bio ?? '')) }}/300</span>
                            <span id="aviso-bio" class="sr-only" aria-live="polite"></span>
                        </div>
                    </div>
                @endif

                <div class="campo">
                    <label for="current_password">Contraseña actual</label>
                    <input id="current_password" type="password" name="current_password" autocomplete="current-password" aria-describedby="ayuda-current_password @error('current_password') error-current_password @enderror" @error('current_password') aria-invalid="true" @enderror>
                    <p id="ayuda-current_password" class="campo-ayuda">Solo hace falta si cambias el correo.</p>
                    @error('current_password') <p id="error-current_password" class="error">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="boton">Guardar cambios</button>
            </form>
        </section>

        {{-- NP-36 paso 3: sección "Contraseña" (cambiar la contraseña). --}}

        {{-- NP-36 paso 4: sección "Sesiones" (sesiones abiertas). --}}

        {{-- NP-36 paso 5: sección "Mis comentarios". --}}

        {{-- NP-36 paso 6: sección "Desactivar cuenta". --}}
    </div>
@endsection
