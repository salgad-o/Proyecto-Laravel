@extends('layouts.app')

@section('title', 'Comentarios')

@section('content')
    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">PANEL DE GESTIÓN</p>
            <h1 id="comentarios-titulo" class="pagina-titulo">Comentarios</h1>
            <p class="pagina-subtitulo">
                Aquí la administración retira los comentarios que incumplen las normas. Nadie puede editar el comentario de otra persona.
            </p>
        </div>
    </div>

    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    @error('page')
        <p class="buscador-error" role="alert">{{ $message }}</p>
    @enderror

    <section aria-labelledby="comentarios-titulo" class="panel-comentarios">
        @if ($comentarios->isEmpty())
            <div class="estado-vacio">
                <p class="estado-vacio-texto">No hay comentarios que moderar.</p>
            </div>
        @else
            {{-- Misma tabla que Redacción: container query, roles ARIA para el modo
                 apilado y contenedor con scroll de seguridad. --}}
            <div class="panel-tabla-contenedor">
                <table class="panel-tabla" role="table">
                    <caption class="sr-only">Comentarios: página {{ $comentarios->currentPage() }} de {{ $comentarios->lastPage() }}</caption>
                    <thead role="rowgroup">
                        <tr role="row">
                            <th scope="col" role="columnheader">Comentario</th>
                            <th scope="col" role="columnheader">Autor</th>
                            <th scope="col" role="columnheader">Noticia</th>
                            <th scope="col" role="columnheader">Fecha</th>
                            <th scope="col" role="columnheader">Acción</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup">
                        @foreach ($comentarios as $comentario)
                            @php
                                // Extracto en una línea: los saltos se convierten en espacios y se
                                // recorta a 120 CARACTERES con mb_substr (multibyte). No Str::limit:
                                // mide ancho visual (un emoji cuenta 2) y podría recortar sin que
                                // $recortado lo sepa, dejando el texto sin "Ver completo".
                                $enUnaLinea = trim((string) preg_replace('/\s+/u', ' ', $comentario->body));
                                $recortado = mb_strlen($enUnaLinea) > 120;
                                $extracto = $recortado ? rtrim(mb_substr($enUnaLinea, 0, 120)).'…' : $enUnaLinea;
                            @endphp
                            <tr role="row">
                                <th scope="row" role="rowheader" class="comentario-celda">
                                    <span class="comentario-extracto">{{ $extracto }}</span>
                                    @if ($recortado)
                                        <details class="auditoria-plegable comentario-completo">
                                            <summary>Ver completo</summary>
                                            {{-- pre-line: respeta los saltos de línea; el texto va escapado con {{ }}. --}}
                                            <p class="texto-preformateado comentario-texto">{{ $comentario->body }}</p>
                                        </details>
                                    @endif
                                </th>
                                <td role="cell" data-columna="Autor">
                                    <span class="panel-tabla-autor auditoria-usuario">
                                        <x-avatar :usuario="$comentario->user" :tamano="28" clase="autor-avatar panel-tabla-avatar" :lazy="true" />
                                        <span>{{ $comentario->user->name }}</span>
                                    </span>
                                </td>
                                <td role="cell" data-columna="Noticia" class="comentario-noticia">
                                    <a href="{{ route('noticias.show', $comentario->noticia) }}#comentarios" class="comentario-noticia-enlace">{{ $comentario->noticia->title }}</a>
                                    @if ($comentario->noticia->status === 'borrador')
                                        <span class="chip chip-estado chip-borrador">Borrador</span>
                                    @endif
                                </td>
                                <td role="cell" data-columna="Fecha">
                                    {{-- Convención del panel: el title es la hora del servidor (UTC, deuda NP-40). --}}
                                    <time datetime="{{ $comentario->created_at->toIso8601String() }}" title="{{ $comentario->created_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $comentario->created_at->gt(now()->subMinute()) ? 'justo ahora' : $comentario->created_at->locale('es')->diffForHumans() }}</time>
                                </td>
                                <td role="cell" class="panel-tabla-acciones-celda">
                                    @can('delete', $comentario)
                                        <form method="POST" action="{{ route('comentarios.destroy', $comentario) }}" class="form-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="boton boton-peligro boton-sm panel-tabla-accion"
                                                    data-confirm="¿Eliminar el comentario de {{ $comentario->user->name }}? No se puede deshacer.">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M3 6h18"></path>
                                                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                                </svg>
                                                Eliminar<span class="sr-only"> comentario de {{ $comentario->user->name }} en «{{ $comentario->noticia->title }}»</span>
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{ $comentarios->onEachSide(1)->links('pagination.nexus', ['etiqueta' => 'Paginación de comentarios']) }}
    </section>
@endsection
