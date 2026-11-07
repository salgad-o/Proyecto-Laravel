@extends('layouts.app')

@section('title', 'Actividad · Mi perfil')

@section('content')
    @include('perfil._cabecera', ['subtitulo' => 'Lo que has publicado y comentado en la revista.'])

    <div class="perfil">
        {{-- Contadores: salen del total de cada paginador (sin consultas extra).
             "Noticias publicadas" solo para quienes publican. --}}
        <dl class="perfil-contadores">
            <div class="perfil-contador">
                <dt>Comentarios</dt>
                <dd>{{ $comentarios->total() }}</dd>
            </div>
            @if ($publica)
                <div class="perfil-contador">
                    <dt>Noticias publicadas</dt>
                    <dd>{{ $noticias->total() }}</dd>
                </div>
            @endif
        </dl>

        {{-- Mis noticias publicadas (solo editor y admin). --}}
        @if ($publica)
            <section class="perfil-tarjeta" aria-labelledby="actividad-noticias-titulo">
                <h2 id="actividad-noticias-titulo" class="perfil-tarjeta-titulo">Mis noticias publicadas</h2>

                @if ($noticias->isEmpty())
                    <p class="perfil-tarjeta-texto">Aún no has publicado noticias.</p>
                @else
                    <ul class="actividad-lista">
                        @foreach ($noticias as $noticia)
                            <li class="actividad-item">
                                <a href="{{ route('noticias.show', $noticia) }}" class="actividad-titulo">{{ $noticia->title }}</a>
                                <p class="actividad-meta">
                                    <time datetime="{{ $noticia->published_at->toIso8601String() }}" title="{{ $noticia->published_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $noticia->published_at->gt(now()->subMinute()) ? 'justo ahora' : $noticia->published_at->locale('es')->diffForHumans() }}</time>
                                    <span>{{ $noticia->comentarios_count }} {{ $noticia->comentarios_count === 1 ? 'comentario' : 'comentarios' }}</span>
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    {{ $noticias->onEachSide(1)->links('pagination.nexus', ['etiqueta' => 'Paginación de mis noticias']) }}
                @endif

                {{-- Enlace al panel de borradores (NP-20), con el nombre que tiene en el menú. --}}
                @php($nombrePanel = auth()->user()->can('viewAllInPanel', \App\Models\Noticia::class) ? 'Redacción' : 'Mis noticias')
                <p class="perfil-fila-ayuda actividad-nota">
                    Tus borradores están en <a href="{{ route('dashboard.noticias.index') }}">{{ $nombrePanel }}</a>.
                </p>
            </section>
        @endif

        {{-- Mis comentarios. Si la noticia ya no se puede ver (por ejemplo, volvió a
             borrador), NO se muestra su título ni su enlace: no se filtra un borrador. --}}
        <section class="perfil-tarjeta" aria-labelledby="actividad-comentarios-titulo">
            <h2 id="actividad-comentarios-titulo" class="perfil-tarjeta-titulo">Mis comentarios</h2>

            @if ($comentarios->isEmpty())
                <p class="perfil-tarjeta-texto">Aún no has comentado ninguna noticia. <a href="{{ route('noticias.index') }}">Ver noticias</a></p>
            @else
                <ul class="actividad-lista">
                    @foreach ($comentarios as $comentario)
                        <li class="actividad-item">
                            <p class="actividad-extracto">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($comentario->body), 160) }}</p>
                            <p class="actividad-meta">
                                <time datetime="{{ $comentario->created_at->toIso8601String() }}" title="{{ $comentario->created_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $comentario->created_at->gt(now()->subMinute()) ? 'justo ahora' : $comentario->created_at->locale('es')->diffForHumans() }}</time>
                                @if ($comentario->edited_at)
                                    <em>(editado)</em>
                                @endif
                            </p>
                            <p class="actividad-meta">
                                @if ($comentario->noticia)
                                    @can('view', $comentario->noticia)
                                        <span class="actividad-noticia">En: <a href="{{ route('noticias.show', $comentario->noticia) }}#comentarios">{{ $comentario->noticia->title }}</a></span>
                                        @can('update', $comentario)
                                            <a href="{{ route('comentarios.edit', $comentario) }}" class="boton boton-secundario boton-sm">Editar</a>
                                        @endcan
                                    @else
                                        <span class="actividad-no-disponible">Noticia no disponible</span>
                                    @endcan
                                @else
                                    <span class="actividad-no-disponible">Noticia no disponible</span>
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>

                {{ $comentarios->onEachSide(1)->links('pagination.nexus', ['etiqueta' => 'Paginación de mis comentarios']) }}
            @endif
        </section>
    </div>
@endsection
