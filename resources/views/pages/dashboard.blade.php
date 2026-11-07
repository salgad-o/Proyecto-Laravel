@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{-- NP-20 paso 7: "Lo que requiere tu atención". Los enlaces de gestión ya están en el
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

    {{-- El contenedor permite que la rejilla dependa del ancho real del contenido
         (container query), no de la ventana: con la barra abierta o en modo iconos. --}}
    <div class="escritorio-contenedor">
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
                <section class="escritorio-bloque" aria-labelledby="bloque-comentarios">
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

            @if ($actividad !== null)
                <section class="escritorio-bloque" aria-labelledby="bloque-actividad">
                    <h2 id="bloque-actividad" class="escritorio-bloque-titulo">Actividad importante</h2>

                    @if ($actividad->isEmpty())
                        <p class="escritorio-vacio">Sin actividad importante reciente. Todo en calma.</p>
                    @else
                        <ul class="escritorio-lista">
                            @foreach ($actividad as $registro)
                                <li class="escritorio-item">
                                    <p class="escritorio-accion">
                                        <span class="chip chip-importancia chip-alta">{{ $registro->etiquetaImportancia() }}</span>
                                        {{ $registro->etiquetaAccion() }}
                                        <span class="escritorio-elemento">{{ $registro->tipoElemento() }} #{{ $registro->auditable_id }}</span>
                                    </p>
                                    <span class="escritorio-meta">
                                        {{ $registro->user?->name ?? 'Sistema' }}
                                        ·
                                        <time datetime="{{ $registro->created_at->toIso8601String() }}" title="{{ $registro->created_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $registro->created_at->gt(now()->subMinute()) ? 'justo ahora' : $registro->created_at->locale('es')->diffForHumans() }}</time>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <a href="{{ route('auditoria.index', ['importancia' => 'alta']) }}" class="escritorio-ver-todo">Ver todo<span class="sr-only"> en la auditoría</span> →</a>
                </section>
            @endif
        </div>
    </div>
@endsection
