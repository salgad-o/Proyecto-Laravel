@extends('layouts.app')

@section('title', 'Inicio')

@section('content')
    @if ($destacada)
        <article class="destacada">
            {{-- Enlace solo para el ratón: los accesibles son el título y "Leer noticia". --}}
            <a href="{{ route('noticias.show', $destacada) }}" class="destacada-bloque" tabindex="-1" aria-hidden="true">
                {{ mb_strtoupper(mb_substr($destacada->title, 0, 1)) }}
            </a>

            <div class="destacada-cuerpo">
                <p class="etiqueta-seccion">NOTICIA DESTACADA</p>
                <h1 class="destacada-titulo">
                    <a href="{{ route('noticias.show', $destacada) }}" class="tarjeta-enlace">{{ $destacada->title }}</a>
                </h1>

                <p class="destacada-resumen">{{ $destacada->excerpt ?: \Illuminate\Support\Str::limit($destacada->body, 140) }}</p>

                <div class="destacada-meta">
                    <span class="autor-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($destacada->user->name, 0, 1)) }}</span>
                    <div class="tarjeta-autor">
                        <span class="autor-nombre">{{ $destacada->user->name }}</span>
                        @if ($destacada->published_at)
                            <time class="noticia-fecha" datetime="{{ $destacada->published_at->toIso8601String() }}">{{ $destacada->published_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}</time>
                        @endif
                    </div>
                    <span class="noticia-fecha">{{ $destacada->minutosLectura() }} min de lectura</span>
                    <span class="chip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        {{ $destacada->comentarios_count }} {{ $destacada->comentarios_count === 1 ? 'comentario' : 'comentarios' }}
                    </span>
                </div>

                <a href="{{ route('noticias.show', $destacada) }}" class="boton">Leer noticia</a>
            </div>
        </article>

        @if ($ultimas->isNotEmpty())
            <section class="ultimas" aria-labelledby="ultimas-titulo">
                <h2 id="ultimas-titulo" class="seccion-titulo">ÚLTIMAS NOTICIAS</h2>

                <div class="rejilla-noticias">
                    @foreach ($ultimas as $noticia)
                        @include('noticias._tarjeta', ['noticia' => $noticia])
                    @endforeach
                </div>
            </section>
        @endif

        <p class="portada-accion">
            <a href="{{ route('noticias.index') }}" class="boton boton-secundario">Ver todas las noticias</a>
        </p>
    @else
        <div class="portada-vacia">
            <h1 class="portada-vacia-texto">Todavía no hay noticias publicadas.</h1>

            @can('create', \App\Models\Noticia::class)
                <a href="{{ route('noticias.create') }}" class="boton">Crear noticia</a>
            @endcan
        </div>
    @endif
@endsection
