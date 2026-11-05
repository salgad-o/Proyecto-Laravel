@extends('layouts.app')

@section('title', 'Mi perfil')

@section('content')
    @include('perfil._cabecera', ['subtitulo' => 'Revisa los datos de tu cuenta y edita los que quieras.'])

    <div class="perfil">
        {{-- Tarjeta superior. Solo la inicial: la foto llegará con NP-39.
             Solo la fecha (sin hora): las horas tienen el desfase UTC de NP-40. --}}
        <section class="perfil-cabecera" aria-labelledby="perfil-cabecera-nombre">
            <div class="perfil-franja"></div>
            <div class="perfil-cabecera-cuerpo">
                <span class="perfil-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($usuario->name, 0, 1)) }}</span>
                <h2 id="perfil-cabecera-nombre" class="perfil-nombre">{{ $usuario->name }}</h2>
                <p class="perfil-cabecera-pie">
                    <span class="chip">{{ $usuario->role?->label ?? 'Sin rol' }}</span>
                    <span class="perfil-miembro">Miembro desde <time datetime="{{ $usuario->created_at->toDateString() }}">{{ $usuario->created_at->format('d/m/Y') }}</time></span>
                </p>
            </div>
        </section>

        {{-- Datos de la cuenta: primero se ven; cada campo editable abre su
             propio formulario con <details> (funciona sin JavaScript). --}}
        <section class="perfil-tarjeta" aria-labelledby="perfil-datos-titulo">
            <h2 id="perfil-datos-titulo" class="perfil-tarjeta-titulo">Datos de la cuenta</h2>

            <div class="perfil-filas">
                {{-- Nombre --}}
                <div class="perfil-fila">
                    <div class="perfil-fila-texto">
                        <p class="perfil-fila-etiqueta">Nombre</p>
                        <p class="perfil-fila-valor">{{ $usuario->name }}</p>
                    </div>
                    <details class="perfil-editar" @if ($errors->has('name')) open @endif>
                        <summary class="boton boton-secundario boton-sm">Editar<span class="sr-only"> nombre</span></summary>
                        <form method="POST" action="{{ route('perfil.nombre') }}" class="perfil-editar-form">
                            @csrf
                            @method('PATCH')
                            <div class="campo">
                                <label for="name">Nombre</label>
                                <input id="name" type="text" name="name" value="{{ old('name', $usuario->name) }}" required maxlength="100" autocomplete="name" @error('name') aria-invalid="true" aria-describedby="error-name" @enderror>
                                @error('name') <p id="error-name" class="error">{{ $message }}</p> @enderror
                            </div>
                            <div class="perfil-editar-botones">
                                <button type="submit" class="boton boton-sm">Guardar</button>
                                <a href="{{ route('perfil.edit') }}" class="boton boton-secundario boton-sm">Cancelar</a>
                            </div>
                        </form>
                    </details>
                </div>

                {{-- Correo: bloqueado. Con él se inicia sesión y se recupera la contraseña;
                     el cambio se pide por Contacto (derecho de actualización, Ley 1581). --}}
                <div class="perfil-fila">
                    <div class="perfil-fila-texto">
                        <p class="perfil-fila-etiqueta">Correo</p>
                        <p class="perfil-fila-valor perfil-correo">
                            <svg class="perfil-candado" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                                <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
                            </svg>
                            <span>{{ $usuario->email }}</span>
                        </p>
                        <p class="perfil-fila-ayuda">Con este correo inicias sesión y recuperas tu contraseña. Para cambiarlo, escríbenos por <a href="{{ route('contact') }}">Contacto</a>.</p>
                    </div>
                </div>

                {{-- Biografía: solo quienes publican (se muestra en sus noticias). --}}
                @if ($editaBio)
                    <div class="perfil-fila">
                        <div class="perfil-fila-texto">
                            <p class="perfil-fila-etiqueta">Biografía</p>
                            @if (filled($usuario->bio))
                                <p class="perfil-fila-valor perfil-bio">{{ $usuario->bio }}</p>
                            @else
                                <p class="perfil-fila-valor perfil-fila-vacia">Aún no has escrito tu biografía.</p>
                            @endif
                            <p class="perfil-fila-ayuda">Se muestra bajo tu nombre en las noticias que publicas.</p>
                        </div>
                        <details class="perfil-editar" @if ($errors->has('bio')) open @endif>
                            <summary class="boton boton-secundario boton-sm">Editar<span class="sr-only"> biografía</span></summary>
                            <form method="POST" action="{{ route('perfil.biografia') }}" class="perfil-editar-form">
                                @csrf
                                @method('PATCH')
                                <div class="campo">
                                    <label for="bio">Biografía</label>
                                    <textarea id="bio" name="bio" rows="4" maxlength="300" data-contador="contador-bio" data-contador-aviso="aviso-bio" @error('bio') aria-invalid="true" aria-describedby="error-bio" @enderror>{{ old('bio', $usuario->bio) }}</textarea>
                                    @error('bio') <p id="error-bio" class="error">{{ $message }}</p> @enderror
                                    <div class="campo-pie">
                                        {{-- Oculto hasta que app.js lo active: sin JavaScript no se actualizaría. --}}
                                        <span id="contador-bio" class="comentario-contador" hidden>{{ mb_strlen(old('bio', $usuario->bio ?? '')) }}/300</span>
                                        <span id="aviso-bio" class="sr-only" aria-live="polite"></span>
                                    </div>
                                </div>
                                <div class="perfil-editar-botones">
                                    <button type="submit" class="boton boton-sm">Guardar</button>
                                    <a href="{{ route('perfil.edit') }}" class="boton boton-secundario boton-sm">Cancelar</a>
                                </div>
                            </form>
                        </details>
                    </div>
                @endif

                {{-- Rol: solo lectura (lo cambia un administrador). --}}
                <div class="perfil-fila">
                    <div class="perfil-fila-texto">
                        <p class="perfil-fila-etiqueta">Rol</p>
                        <p class="perfil-fila-valor">{{ $usuario->role?->label ?? 'Sin rol' }}</p>
                        @if (filled($usuario->role?->description))
                            <p class="perfil-fila-ayuda">{{ $usuario->role->description }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        {{-- Privacidad: prueba de la autorización legal (NP-43). Sin fecha inventada
             para las cuentas creadas antes de que existiera el registro. --}}
        <section class="perfil-tarjeta" aria-labelledby="perfil-privacidad-titulo">
            <h2 id="perfil-privacidad-titulo" class="perfil-tarjeta-titulo">Privacidad</h2>

            @if ($aceptoTerminosEl)
                <p class="perfil-tarjeta-texto">Aceptaste los <a href="{{ route('legal.terminos') }}">Términos de uso</a> y la <a href="{{ route('legal.privacidad') }}">Política de privacidad</a> (versión {{ $versionLegal }}) el <time datetime="{{ $aceptoTerminosEl->toDateString() }}">{{ $aceptoTerminosEl->format('d/m/Y') }}</time>.</p>
            @else
                <p class="perfil-tarjeta-texto">Tu cuenta se creó antes de que existiera el registro de aceptación.</p>
            @endif
        </section>

        {{-- La contraseña y las sesiones viven en la pestaña "Seguridad" (perfil/seguridad). --}}
        {{-- Actividad y Cuenta tienen sus propias pestañas (perfil/actividad y perfil/cuenta). --}}
    </div>
@endsection
