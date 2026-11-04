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

        {{-- NP-36 paso 4: sesiones activas. Solo textos ya interpretados: nunca el user_agent
             crudo, el id de sesión ni el payload. Errores en su propia bolsa ('sesiones')
             para no mezclarse con el current_password del formulario de contraseña. --}}
        <section class="perfil-tarjeta" aria-labelledby="perfil-sesiones-titulo">
            <h2 id="perfil-sesiones-titulo" class="perfil-tarjeta-titulo">Sesiones activas</h2>

            @if (! $sesionesDisponibles)
                <p class="perfil-tarjeta-texto">No se pueden mostrar las sesiones con la configuración actual.</p>
            @else
                <p class="perfil-tarjeta-texto">Dispositivos donde tu cuenta tiene la sesión abierta.</p>

                @if ($sesiones->isNotEmpty())
                    <ul class="dispositivos-lista">
                        @foreach ($sesiones as $sesion)
                            <li class="dispositivo">
                                <svg class="dispositivo-icono" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="4" width="18" height="12" rx="2"></rect>
                                    <path d="M8 20h8"></path>
                                    <path d="M12 16v4"></path>
                                </svg>
                                <div class="dispositivo-datos">
                                    <p class="dispositivo-titulo">
                                        <span>{{ $sesion->descripcion() }}</span>
                                        @if ($sesion->esActual)
                                            <span class="chip">Esta sesión</span>
                                        @endif
                                    </p>
                                    <p class="dispositivo-detalle">
                                        <span>IP {{ $sesion->ip ?? 'desconocida' }}</span>
                                        @if ($sesion->esActual)
                                            <span>Activa ahora</span>
                                        @else
                                            {{-- Hora del servidor (UTC, deuda NP-40): por eso la zona va escrita. --}}
                                            <span>Última actividad: <time datetime="{{ $sesion->ultimaActividad->toIso8601String() }}" title="{{ $sesion->ultimaActividad->format('d/m/Y H:i T') }}">{{ $sesion->ultimaActividad->locale('es')->diffForHumans() }}</time></span>
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if (! $hayOtrasSesiones)
                    <p class="perfil-fila-ayuda">No tienes otras sesiones abiertas.</p>
                @else
                    <form method="POST" action="{{ route('perfil.sesiones') }}" class="perfil-formulario-seguridad">
                        @csrf
                        @method('DELETE')

                        <div class="campo">
                            <label for="sesiones_current_password">Contraseña actual</label>
                            <input id="sesiones_current_password" type="password" name="current_password" required autocomplete="current-password" aria-describedby="ayuda-sesiones @error('current_password', 'sesiones') error-sesiones_current_password @enderror" @error('current_password', 'sesiones') aria-invalid="true" @enderror>
                            <p id="ayuda-sesiones" class="perfil-fila-ayuda">Escríbela para confirmar. Esta sesión seguirá abierta.</p>
                            @error('current_password', 'sesiones') <p id="error-sesiones_current_password" class="error">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="boton boton-secundario">Cerrar sesión en los demás dispositivos</button>
                    </form>
                @endif
            @endif
        </section>
    </div>
@endsection
