@extends('layouts.app')

@section('title', 'Noticias')

@section('content')
    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">ARCHIVO DE REDACCIÓN</p>
            <h1 class="pagina-titulo">NOTICIAS</h1>
            <p class="pagina-subtitulo">Crónicas, análisis y novedades del mundo de los videojuegos.</p>
        </div>
        {{-- NP-20 paso 4: sin "crear" en la cabecera; crear es gestión y vive en el panel.
             El estado vacío sí lo ofrece a quien puede. --}}
    </div>

    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    <form method="GET" action="{{ route('noticias.index') }}" class="buscador-noticias" role="search">
        <div class="buscador-noticias-campo">
            <label for="q">Buscar noticias</label>
            <input id="q" type="search" name="q" value="{{ is_string(old('q')) ? old('q') : $q }}" maxlength="100"
                   placeholder="Título o resumen">
            @error('q') <p class="buscador-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="buscador-noticias-acciones">
            <button type="submit" class="boton">Buscar</button>

            @if (filled($q))
                <a href="{{ route('noticias.index') }}" class="buscador-noticias-limpiar">Limpiar</a>
            @endif
        </div>
    </form>

    @if (filled($q))
        <p class="resultados-busqueda" role="status">
            Resultados para "{{ $q }}" ({{ $noticias->total() }})
        </p>
    @endif

    @if ($noticias->isNotEmpty())
        <div class="lista-noticias">
            @foreach ($noticias as $noticia)
                @include('noticias._tarjeta', ['noticia' => $noticia, 'variante' => 'horizontal', 'nivel' => 2])
            @endforeach
        </div>
    @else
        <div class="estado-vacio">
            @if (filled($q))
                <p class="estado-vacio-texto">No encontramos noticias para "{{ $q }}".</p>
                <a href="{{ route('noticias.index') }}" class="boton boton-secundario">Limpiar búsqueda</a>
            @else
                <p class="estado-vacio-texto">No hay noticias publicadas todavía.</p>

                @can('create', \App\Models\Noticia::class)
                    <a href="{{ route('noticias.create') }}" class="boton">+ Nuevo artículo</a>
                @endcan
            @endif
        </div>
    @endif

    {{ $noticias->onEachSide(1)->links('pagination.nexus') }}
@endsection
