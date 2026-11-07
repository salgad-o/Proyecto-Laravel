@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{-- NP-20 paso 7 / NP-21 paso 2: "Lo que requiere tu atención". Los enlaces de gestión ya están en el
         menú lateral. Cada bloque restringido llega como null desde el controlador si la
         Policy no lo permite: aquí solo se comprueba si la variable existe. --}}
    <div class="escritorio-saludo">
        <div class="escritorio-saludo-texto">
            <x-avatar :usuario="$usuario" :tamano="48" clase="autor-avatar escritorio-avatar" />
            <div>
                <p class="pagina-etiqueta">PANEL DE GESTIÓN</p>
                <h1 class="escritorio-titulo">Hola, {{ $usuario->name }}</h1>
            </div>
        </div>

        @can('create', \App\Models\Noticia::class)
            <a href="{{ route('noticias.create') }}" class="boton pagina-accion">+ Nuevo artículo</a>
        @endcan
    </div>

    {{-- NP-21 paso 2: franja de cifras, columna principal (bloques) y panel lateral de actividad.
         Todo depende del ancho real del contenido (container queries), no de la ventana.
         El <aside> va después en el DOM: en una columna queda al final. --}}
    <div class="escritorio-raiz">
        {{-- Cifras (ajuste 2c): franja a todo el ancho, ANTES del layout. Un <dl> con un
             <div> por tarjeta (dt = etiqueta, dd = número). --}}
        <dl class="escritorio-kpis" aria-label="Resumen en cifras">
            @foreach ($kpis as $kpi)
                <div class="escritorio-kpi" data-kpi="{{ $kpi['clave'] }}">
                    <dt class="escritorio-kpi-etiqueta">{{ $kpi['etiqueta'] }}</dt>
                    <dd class="escritorio-kpi-valor">{{ number_format($kpi['valor'], 0, ',', '.') }}</dd>
                    @isset($kpi['desglose'])
                        <dd class="escritorio-kpi-desglose">
                            @foreach ($kpi['desglose'] as $parte)
                                @php
                                    // Plural con trans_choice (lang/es/panel.php): "1 administrador",
                                    // "2 editores". Un rol sin clave usa su nombre como respaldo.
                                    $cifra = number_format($parte['valor'], 0, ',', '.');
                                    $clave = 'panel.roles.'.$parte['vista'];
                                    $texto = \Illuminate\Support\Facades\Lang::has($clave)
                                        ? trans_choice($clave, $parte['valor'], ['count' => $cifra])
                                        : $cifra.' '.$parte['vista'];
                                @endphp
                                <a href="{{ route('usuarios.index', ['vista' => $parte['vista']]) }}" class="escritorio-enlace-secundario">{{ $texto }}</a>@if (! $loop->last) · @endif
                            @endforeach
                        </dd>
                    @endisset
                </div>
            @endforeach
        </dl>

        {{-- Sin panel lateral (null desde el controlador), la columna principal ocupa todo el ancho. --}}
        <div @class(['escritorio-layout', 'escritorio-layout-sin-lateral' => $actividad === null])>
            <div class="escritorio-principal escritorio-contenedor">

                <div class="escritorio">
                    <section class="escritorio-bloque" aria-labelledby="bloque-borradores">
                        <h2 id="bloque-borradores" class="escritorio-bloque-titulo">Tus borradores</h2>

                        @if ($borradores->isEmpty())
                            <p class="escritorio-vacio">No tienes borradores. ¡Empieza uno nuevo!</p>
                        @else
                            <ul class="escritorio-lista">
                                @foreach ($borradores as $noticia)
                                    <li class="escritorio-item">
                                        @can('update', $noticia)
                                            <a href="{{ route('noticias.edit', $noticia) }}" class="escritorio-enlace">{{ $noticia->title }}</a>
                                        @else
                                            <a href="{{ route('noticias.show', $noticia) }}" class="escritorio-enlace">{{ $noticia->title }}</a>
                                        @endcan
                                        <span class="escritorio-meta">
                                            Editado
                                            {{-- Convención del panel: el title es la hora del servidor (UTC, deuda NP-40). --}}
                                            <time datetime="{{ $noticia->updated_at->toIso8601String() }}" title="{{ $noticia->updated_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $noticia->updated_at->gt(now()->subMinute()) ? 'justo ahora' : $noticia->updated_at->locale('es')->diffForHumans() }}</time>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <a href="{{ route('dashboard.noticias.index', ['estado' => 'borrador']) }}" class="escritorio-ver-todo">Ver todo<span class="sr-only"> en tus borradores</span> →</a>
                    </section>

                    <section class="escritorio-bloque" aria-labelledby="bloque-publicaciones">
                        <h2 id="bloque-publicaciones" class="escritorio-bloque-titulo">Tus publicaciones recientes</h2>

                        @if ($publicaciones->isEmpty())
                            <p class="escritorio-vacio">Aún no has publicado noticias.</p>
                        @else
                            <ul class="escritorio-lista">
                                @foreach ($publicaciones as $noticia)
                                    <li class="escritorio-item">
                                        <a href="{{ route('noticias.show', $noticia) }}" class="escritorio-enlace">{{ $noticia->title }}</a>
                                        <span class="escritorio-meta">
                                            @if ($noticia->published_at)
                                                Publicada
                                                <time datetime="{{ $noticia->published_at->toIso8601String() }}" title="{{ $noticia->published_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $noticia->published_at->gt(now()->subMinute()) ? 'justo ahora' : $noticia->published_at->locale('es')->diffForHumans() }}</time>
                                            @endif
                                            @can('update', $noticia)
                                                @if ($noticia->published_at) · @endif
                                                <a href="{{ route('noticias.edit', $noticia) }}" class="escritorio-enlace-secundario">Editar<span class="sr-only"> «{{ $noticia->title }}»</span></a>
                                            @endcan
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <a href="{{ route('dashboard.noticias.index', ['estado' => 'publicada']) }}" class="escritorio-ver-todo">Ver todo<span class="sr-only"> en tus publicaciones</span> →</a>
                    </section>

                    @if ($comentarios !== null)
                        {{-- A todo el ancho de la rejilla de bloques. --}}
                        <section class="escritorio-bloque escritorio-bloque-ancho" aria-labelledby="bloque-comentarios">
                            <h2 id="bloque-comentarios" class="escritorio-bloque-titulo">Comentarios recientes</h2>

                            @if ($comentarios->isEmpty())
                                <p class="escritorio-vacio">Todavía no hay comentarios. Aparecerán aquí en cuanto los lectores participen.</p>
                            @else
                                <ul class="escritorio-lista">
                                    @foreach ($comentarios as $comentario)
                                        @php
                                            // Una línea, recortada a 80 caracteres con mb_substr (multibyte).
                                            $enUnaLinea = trim((string) preg_replace('/\s+/u', ' ', $comentario->body));
                                            $extracto = mb_strlen($enUnaLinea) > 80 ? rtrim(mb_substr($enUnaLinea, 0, 80)).'…' : $enUnaLinea;
                                        @endphp
                                        <li class="escritorio-item">
                                            <p class="escritorio-cita">{{ $extracto }}</p>
                                            <span class="escritorio-meta">
                                                <x-avatar :usuario="$comentario->user" :tamano="28" clase="autor-avatar panel-tabla-avatar" :lazy="true" />
                                                <span>{{ $comentario->user->name }}</span>
                                                en
                                                <a href="{{ route('noticias.show', $comentario->noticia_id) }}#comentarios" class="escritorio-enlace-secundario">{{ $comentario->noticia->title }}</a>
                                                ·
                                                <time datetime="{{ $comentario->created_at->toIso8601String() }}" title="{{ $comentario->created_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $comentario->created_at->gt(now()->subMinute()) ? 'justo ahora' : $comentario->created_at->locale('es')->diffForHumans() }}</time>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <a href="{{ route('comentarios.index') }}" class="escritorio-ver-todo">Ver todo<span class="sr-only"> en comentarios</span> →</a>
                        </section>
                    @endif
                </div>
            </div>

            {{-- Panel lateral (AuditLogPolicy): con viewAny, toda la actividad reciente
                 (todos los niveles); con viewOwn, solo la propia; sin ninguna, no existe.
                 Nunca IP ni navegador (ni se consultan). --}}
            @if ($actividad !== null)
                <aside class="escritorio-lateral" aria-labelledby="lateral-titulo">
                    <h2 id="lateral-titulo" class="escritorio-bloque-titulo">{{ $actividadGlobal ? 'Actividad reciente' : 'Tu actividad' }}</h2>

                    @if ($actividad->isEmpty())
                        <p class="escritorio-vacio">{{ $actividadGlobal ? 'Todavía no hay actividad registrada.' : 'Todavía no tienes actividad registrada.' }}</p>
                    @else
                        <ol class="escritorio-lista">
                            @foreach ($actividad as $registro)
                                <li class="escritorio-item">
                                    <p class="escritorio-accion">
                                        <span @class([
                                            'chip',
                                            'chip-importancia',
                                            'chip-alta' => $registro->importancia() === 'alta',
                                            'chip-media' => $registro->importancia() === 'media',
                                            'chip-baja' => $registro->importancia() === 'baja',
                                        ])>{{ $registro->etiquetaImportancia() }}</span>
                                        {{ $registro->etiquetaAccion() }}
                                        <span class="escritorio-elemento">{{ $registro->tipoElemento() }} #{{ $registro->auditable_id }}</span>
                                    </p>
                                    <span class="escritorio-meta">
                                        @if ($actividadGlobal)
                                            {{ $registro->user?->name ?? 'Sistema' }}
                                            ·
                                        @endif
                                        <time datetime="{{ $registro->created_at->toIso8601String() }}" title="{{ $registro->created_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $registro->created_at->gt(now()->subMinute()) ? 'justo ahora' : $registro->created_at->locale('es')->diffForHumans() }}</time>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    {{-- Solo el admin tiene a dónde ampliar: /perfil/actividad muestra otra cosa
                         (noticias y comentarios), así que el editor no lleva enlace. --}}
                    @can('viewAny', \App\Models\AuditLog::class)
                        <a href="{{ route('auditoria.index') }}" class="escritorio-ver-todo">Ver auditoría →</a>
                    @endcan
                </aside>
            @endif
        </div>
    </div>
@endsection
