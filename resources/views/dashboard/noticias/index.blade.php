@extends('layouts.app')

@section('title', 'Panel de noticias')

@section('content')
    @php
        $qMostrado = is_string(old('q')) ? old('q') : ($q ?? '');
        $estadoMostrado = is_string(old('estado')) ? old('estado') : ($estado ?? '');
        $estadoMostrado = $estadoMostrado === '' ? null : $estadoMostrado;
        $hayFiltros = $qMostrado !== '' || $estadoMostrado !== null;
    @endphp

    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">PANEL DE REDACCIÓN</p>
            <h1 id="panel-noticias-titulo" class="pagina-titulo">NOTICIAS</h1>
            <p class="pagina-subtitulo">
                @if ($usuario->isAdmin())
                    Todas las noticias y borradores de la revista.
                @else
                    Tus noticias y borradores. Solo tú puedes ver los que aún no están publicados.
                @endif
            </p>
        </div>

        @can('create', \App\Models\Noticia::class)
            <a href="{{ route('noticias.create') }}" class="boton pagina-accion">+ Nuevo Artículo</a>
        @endcan
    </div>

    <nav class="panel-estado-tabs" aria-label="Filtrar por estado">
        @foreach ([
            ['valor' => null, 'texto' => 'Todas'],
            ['valor' => 'publicada', 'texto' => 'Publicadas'],
            ['valor' => 'borrador', 'texto' => 'Borradores'],
        ] as $filtro)
            <a href="{{ route('dashboard.noticias.index', array_filter(['q' => $qMostrado, 'estado' => $filtro['valor']], fn ($dato) => $dato !== null && $dato !== '')) }}"
               @class(['panel-estado-tab', 'panel-estado-activo' => $estadoMostrado === $filtro['valor']])
               @if ($estadoMostrado === $filtro['valor']) aria-current="page" @endif>
                {{ $filtro['texto'] }}
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('dashboard.noticias.index') }}" class="buscador-noticias" role="search">
        <div class="buscador-noticias-campo">
            <label for="panel-q">Buscar noticias</label>
            <input id="panel-q" type="search" name="q" value="{{ $qMostrado }}" maxlength="100" placeholder="Título o resumen">
            @error('q')
                <p class="buscador-error">{{ $message }}</p>
            @enderror
        </div>

        @if ($estadoMostrado !== null)
            <input type="hidden" name="estado" value="{{ $estadoMostrado }}">
        @endif

        <div class="buscador-noticias-acciones">
            <button type="submit" class="boton">Buscar</button>
            @if ($hayFiltros)
                <a href="{{ route('dashboard.noticias.index') }}" class="buscador-noticias-limpiar">Limpiar</a>
            @endif
        </div>
    </form>

    @error('estado')
        <p class="buscador-error">{{ $message }}</p>
    @enderror

    <section aria-labelledby="panel-noticias-titulo">
        <div class="lista-noticias">
            @forelse ($noticias as $noticia)
                @include('noticias._tarjeta', [
                    'noticia' => $noticia,
                    'variante' => 'horizontal',
                    'nivel' => 2,
                    'mostrarEstado' => true,
                ])
            @empty
                <div class="estado-vacio">
                    <p class="estado-vacio-texto">
                        @if ($hayFiltros)
                            No hay noticias que coincidan con el filtro.
                        @else
                            Aún no hay noticias en el panel.
                        @endif
                    </p>
                    @if ($hayFiltros)
                        <a href="{{ route('dashboard.noticias.index') }}" class="boton boton-secundario">Limpiar filtros</a>
                    @else
                        @can('create', \App\Models\Noticia::class)
                            <a href="{{ route('noticias.create') }}" class="boton">+ Nuevo Artículo</a>
                        @endcan
                    @endif
                </div>
            @endforelse
        </div>

        {{ $noticias->onEachSide(1)->links('pagination.nexus', ['etiqueta' => 'Paginación del panel de noticias']) }}
    </section>
@endsection
