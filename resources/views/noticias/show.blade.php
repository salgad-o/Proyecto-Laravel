@extends('layouts.app')

@section('title', $noticia->title)

@section('content')
    @if (session('aviso_noticia') && session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    <p class="enlace-volver-linea"><a href="{{ route('noticias.index') }}" class="enlace-volver">← Volver a noticias</a></p>

    @if ($noticia->status === 'borrador')
        <p class="aviso-borrador">Borrador: solo tú y la administración pueden ver esta noticia</p>
    @endif

    <div class="detalle-bloque" aria-hidden="true">{{ mb_strtoupper(mb_substr($noticia->title, 0, 1)) }}</div>

    <div @class(['detalle-rejilla', 'detalle-rejilla-unica' => $masNoticias->isEmpty()])>
        <article class="detalle-articulo">
            <div class="detalle-encabezado">
                <h1 class="detalle-titulo">{{ $noticia->title }}</h1>

                @canany(['update', 'delete'], $noticia)
                    <div class="detalle-acciones">
                        @can('update', $noticia)
                            <a href="{{ route('noticias.edit', $noticia) }}" class="boton boton-secundario boton-sm">Editar</a>
                        @endcan

                        @can('delete', $noticia)
                            <form method="POST" action="{{ route('noticias.destroy', $noticia) }}" class="form-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="boton boton-peligro boton-sm" data-confirm="¿Seguro que quieres eliminar esta noticia?">Eliminar</button>
                            </form>
                        @endcan
                    </div>
                @endcanany
            </div>

            <div class="detalle-meta">
                <span class="autor-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($noticia->user->name, 0, 1)) }}</span>
                <span class="autor-nombre">{{ $noticia->user->name }}</span>
                @if ($noticia->published_at)
                    <time class="noticia-fecha" datetime="{{ $noticia->published_at->toIso8601String() }}">{{ $noticia->published_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}</time>
                @endif
                <span class="noticia-fecha">{{ $noticia->minutosLectura() }} min de lectura</span>
                <span class="chip">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                    {{ $noticia->comentarios->count() }} {{ $noticia->comentarios->count() === 1 ? 'comentario' : 'comentarios' }}
                </span>
            </div>

            @if ($noticia->excerpt)
                <p class="detalle-resumen">{{ $noticia->excerpt }}</p>
            @endif

            {{-- El cuerpo es texto plano: cada párrafo se escapa con {{ }} y pre-line respeta los saltos simples. --}}
            {{-- Separa por líneas en blanco, aunque tengan espacios o tabulaciones, y descarta los párrafos vacíos. --}}
            <div class="detalle-cuerpo">
                @foreach (preg_split('/\R(?:[ \t]*\R)+/u', trim($noticia->body), -1, PREG_SPLIT_NO_EMPTY) as $parrafo)
                    <p class="detalle-parrafo">{{ $parrafo }}</p>
                @endforeach
            </div>
        </article>

        @if ($masNoticias->isNotEmpty())
            <aside class="detalle-aside" aria-labelledby="mas-noticias-titulo">
                <h2 id="mas-noticias-titulo" class="detalle-aside-titulo">Más noticias</h2>

                <div class="mini-lista">
                    @foreach ($masNoticias as $otra)
                        <article class="mini-noticia">
                            <div class="mini-noticia-bloque" aria-hidden="true">{{ mb_strtoupper(mb_substr($otra->title, 0, 1)) }}</div>
                            <div class="mini-noticia-texto">
                                <h3 class="mini-noticia-titulo">
                                    <a href="{{ route('noticias.show', $otra) }}" class="tarjeta-enlace">{{ $otra->title }}</a>
                                </h3>
                                @if ($otra->published_at)
                                    <time class="noticia-fecha" datetime="{{ $otra->published_at->toIso8601String() }}">{{ $otra->published_at->locale('es')->diffForHumans() }}</time>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </aside>
        @endif

        @include('comentarios._seccion', ['noticia' => $noticia])
    </div>
@endsection
