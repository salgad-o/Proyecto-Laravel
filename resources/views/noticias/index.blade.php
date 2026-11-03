@extends('layouts.app')

@section('title', 'Noticias')

@section('content')
    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">ARCHIVO DE REDACCIÓN</p>
            <h1 class="pagina-titulo">NOTICIAS</h1>
            <p class="pagina-subtitulo">Crónicas, análisis y novedades del mundo de los videojuegos.</p>
        </div>

        @can('create', \App\Models\Noticia::class)
            <a href="{{ route('noticias.create') }}" class="boton pagina-accion">+ Nueva noticia</a>
        @endcan
    </div>

    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    @if ($noticias->isNotEmpty())
        <div class="lista-noticias">
            @foreach ($noticias as $noticia)
                @include('noticias._tarjeta', ['noticia' => $noticia, 'variante' => 'horizontal', 'nivel' => 2])
            @endforeach
        </div>
    @else
        <div class="estado-vacio">
            <p class="estado-vacio-texto">No hay noticias publicadas todavía.</p>

            @can('create', \App\Models\Noticia::class)
                <a href="{{ route('noticias.create') }}" class="boton">Crear noticia</a>
            @endcan
        </div>
    @endif

    {{ $noticias->onEachSide(1)->links('pagination.nexus') }}
@endsection
