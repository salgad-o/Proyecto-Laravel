@php
    // NP-20 paso 3: el nombre sale de NoticiaPolicy::viewAllInPanel ($verTodas, del
    // controlador), la misma habilidad que decide qué noticias lista el panel.
    $nombrePanel = $verTodas ? 'Redacción' : 'Mis noticias';
@endphp

@extends('layouts.app')

@section('title', $nombrePanel)

@section('content')
    @php
        $qMostrado = is_string(old('q')) ? old('q') : ($q ?? '');
        $estadoMostrado = is_string(old('estado')) ? old('estado') : ($estado ?? '');
        $estadoMostrado = $estadoMostrado === '' ? null : $estadoMostrado;
        $hayFiltros = $qMostrado !== '' || $estadoMostrado !== null;
    @endphp

    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">PANEL DE GESTIÓN</p>
            <h1 id="panel-noticias-titulo" class="pagina-titulo">{{ $nombrePanel }}</h1>
            <p class="pagina-subtitulo">
                @if ($verTodas)
                    Todas las noticias y borradores de la revista.
                @else
                    {{-- Cierto según NoticiaPolicy::view: un borrador lo ven su autor y los administradores. --}}
                    Tus noticias y borradores. Solo tú y la administración ven los que aún no están publicados.
                @endif
            </p>
        </div>

        @can('create', \App\Models\Noticia::class)
            <a href="{{ route('noticias.create') }}" class="boton pagina-accion">+ Nuevo artículo</a>
        @endcan
    </div>

    {{-- Contadores: mismo alcance y misma búsqueda que la lista, sin el filtro de estado.
         El número visible "(N)" se oculta al lector de pantalla, que oye "Borradores, 2 noticias". --}}
    {{-- NP-20 paso 4: aviso tras eliminar (mismo patrón que la lista pública). --}}
    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    <nav class="panel-estado-tabs" aria-label="Filtrar por estado">
        @foreach ([
            ['valor' => null, 'texto' => 'Todas', 'total' => $contadores['todas']],
            ['valor' => 'publicada', 'texto' => 'Publicadas', 'total' => $contadores['publicada']],
            ['valor' => 'borrador', 'texto' => 'Borradores', 'total' => $contadores['borrador']],
        ] as $filtro)
            <a href="{{ route('dashboard.noticias.index', array_filter(['q' => $qMostrado, 'estado' => $filtro['valor']], fn ($dato) => $dato !== null && $dato !== '')) }}"
               @class(['panel-estado-tab', 'panel-estado-activo' => $estadoMostrado === $filtro['valor']])
               @if ($estadoMostrado === $filtro['valor']) aria-current="page" @endif>
                {{ $filtro['texto'] }} <span class="panel-estado-total" aria-hidden="true">({{ $filtro['total'] }})</span><span class="sr-only">, {{ $filtro['total'] }} {{ $filtro['total'] === 1 ? 'noticia' : 'noticias' }}</span>
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

    <section aria-labelledby="panel-noticias-titulo" class="panel-noticias">
        @if ($noticias->isEmpty())
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
                        <a href="{{ route('noticias.create') }}" class="boton">+ Nuevo artículo</a>
                    @endcan
                @endif
            </div>
        @else
            {{-- En pantallas estrechas cada fila se apila como un bloque y cada dato
                 muestra su columna con data-columna + ::before (CSS, no estilo en línea). --}}
            <div class="panel-tabla-contenedor">
                <table class="panel-tabla" role="table">
                    <caption class="sr-only">{{ $nombrePanel }}: noticias del panel, página {{ $noticias->currentPage() }} de {{ $noticias->lastPage() }}</caption>
                    <thead role="rowgroup">
                        <tr role="row">
                            <th scope="col" role="columnheader">Título</th>
                            <th scope="col" role="columnheader">Estado</th>
                            @if ($verTodas)
                                <th scope="col" role="columnheader">Autor</th>
                            @endif
                            <th scope="col" role="columnheader">Actualizada</th>
                            <th scope="col" role="columnheader">Comentarios</th>
                            <th scope="col" role="columnheader">Acciones</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup">
                        @foreach ($noticias as $noticia)
                            <tr role="row">
                                <th scope="row" role="rowheader" class="panel-tabla-titulo">
                                    <a href="{{ route('noticias.show', $noticia) }}" class="panel-tabla-enlace">{{ $noticia->title }}</a>
                                    @if ($noticia->excerpt)
                                        {{-- Recortado también en el servidor: todo el <th> es el encabezado de la fila
                                             y el lector de pantalla lo repite al recorrer las celdas. --}}
                                        <span class="panel-tabla-resumen">{{ \Illuminate\Support\Str::limit($noticia->excerpt, 90) }}</span>
                                    @endif
                                </th>
                                <td role="cell" data-columna="Estado">
                                    <span @class([
                                        'chip',
                                        'chip-estado',
                                        'chip-publicada' => $noticia->status === 'publicada',
                                        'chip-borrador' => $noticia->status === 'borrador',
                                    ])>{{ $noticia->status === 'publicada' ? 'Publicada' : 'Borrador' }}</span>
                                </td>
                                @if ($verTodas)
                                    <td role="cell" data-columna="Autor">
                                        <span class="panel-tabla-autor">
                                            <x-avatar :usuario="$noticia->user" :tamano="28" clase="autor-avatar panel-tabla-avatar" :lazy="true" />
                                            <span>{{ $noticia->user->name }}</span>
                                        </span>
                                    </td>
                                @endif
                                <td role="cell" data-columna="Actualizada">
                                    {{-- Misma convención que perfil/actividad: la fecha absoluta del title
                                         es la hora del servidor (UTC, deuda NP-40). --}}
                                    <time datetime="{{ $noticia->updated_at->toIso8601String() }}" title="{{ $noticia->updated_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $noticia->updated_at->gt(now()->subMinute()) ? 'justo ahora' : $noticia->updated_at->locale('es')->diffForHumans() }}</time>
                                </td>
                                <td role="cell" data-columna="Comentarios" class="panel-tabla-numero">{{ $noticia->comentarios_count }}</td>
                                <td role="cell" class="panel-tabla-acciones-celda">
                                    @canany(['update', 'delete'], $noticia)
                                        <div class="panel-tabla-acciones">
                                            @can('update', $noticia)
                                                <a href="{{ route('noticias.edit', $noticia) }}" class="boton boton-secundario boton-sm panel-tabla-accion">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M12 20h9"></path>
                                                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"></path>
                                                    </svg>
                                                    Editar<span class="sr-only"> «{{ $noticia->title }}»</span>
                                                </a>
                                            @endcan

                                            @can('delete', $noticia)
                                                <form method="POST" action="{{ route('noticias.destroy', $noticia) }}" class="form-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="boton boton-peligro boton-sm panel-tabla-accion" data-confirm="¿Seguro que quieres eliminar «{{ $noticia->title }}»?">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                            <path d="M3 6h18"></path>
                                                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                                        </svg>
                                                        Eliminar<span class="sr-only"> «{{ $noticia->title }}»</span>
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    @endcanany
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{ $noticias->onEachSide(1)->links('pagination.nexus', ['etiqueta' => 'Paginación de '.mb_strtolower($nombrePanel)]) }}
    </section>
@endsection
