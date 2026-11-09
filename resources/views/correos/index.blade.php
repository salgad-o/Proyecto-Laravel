@extends('layouts.app')

@section('title', 'Correos recibidos')

@section('content')
    @php
        $pestanas = [
            'todos' => 'Todos',
            'no-leidos' => 'No leídos',
        ];
        $hayFiltro = $estado !== 'todos';

        // Filtro y página que el detalle usará para "Volver" (sin los valores por defecto).
        $volver = array_filter([
            'estado' => $hayFiltro ? $estado : null,
            'page' => $correos->currentPage() > 1 ? $correos->currentPage() : null,
        ]);
    @endphp

    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">PANEL DE GESTIÓN</p>
            <h1 id="correos-titulo" class="pagina-titulo">Correos recibidos</h1>
            <p class="pagina-subtitulo">
                Correos que llegaron al buzón del proyecto (se importan con mail:receive). Su contenido lo escribió el remitente: trátalo con precaución.
            </p>
        </div>
    </div>

    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    @error('estado')
        <p class="buscador-error" role="alert">{{ $message }}</p>
    @enderror
    @error('page')
        <p class="buscador-error" role="alert">{{ $message }}</p>
    @enderror

    {{-- Contadores de una sola consulta. El número visible "(N)" se oculta al lector,
         que oye "No leídos, 2 correos". --}}
    <nav class="panel-estado-tabs" aria-label="Filtrar correos">
        @foreach ($pestanas as $valor => $texto)
            <a href="{{ route('correos.index', $valor === 'todos' ? [] : ['estado' => $valor]) }}"
               @class(['panel-estado-tab', 'panel-estado-activo' => $estado === $valor])
               @if ($estado === $valor) aria-current="page" @endif>
                {{ $texto }} <span class="panel-estado-total" aria-hidden="true">({{ $contadores[$valor] }})</span><span class="sr-only">, {{ $contadores[$valor] }} {{ $contadores[$valor] === 1 ? 'correo' : 'correos' }}</span>
            </a>
        @endforeach
    </nav>

    <section aria-labelledby="correos-titulo" class="panel-correos">
        @if ($correos->isEmpty())
            <div class="estado-vacio">
                <p class="estado-vacio-texto">
                    @if ($hayFiltro)
                        No hay correos sin leer.
                    @else
                        Aún no hay correos recibidos.
                    @endif
                </p>
                @if ($hayFiltro)
                    <a href="{{ route('correos.index') }}" class="boton boton-secundario">Ver todos los correos</a>
                @endif
            </div>
        @else
            <div class="panel-tabla-contenedor">
                <table class="panel-tabla" role="table">
                    <caption class="sr-only">Correos recibidos ({{ $pestanas[$estado] }}): página {{ $correos->currentPage() }} de {{ $correos->lastPage() }}</caption>
                    <thead role="rowgroup">
                        <tr role="row">
                            <th scope="col" role="columnheader">Asunto</th>
                            <th scope="col" role="columnheader">Estado</th>
                            <th scope="col" role="columnheader">Remitente</th>
                            <th scope="col" role="columnheader">Fecha</th>
                            <th scope="col" role="columnheader">Adjuntos</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup">
                        @foreach ($correos as $correo)
                            <tr role="row" @class(['correo-fila-no-leida' => ! $correo->is_read])>
                                <th scope="row" role="rowheader" class="panel-tabla-titulo">
                                    <a href="{{ route('correos.show', ['correo' => $correo, ...$volver]) }}" class="panel-tabla-enlace correo-asunto-enlace">{{ $correo->subject ?? '(sin asunto)' }}</a>
                                </th>
                                <td role="cell" data-columna="Estado">
                                    {{-- El estado se distingue por TEXTO, no solo por el estilo de la fila. --}}
                                    <span @class([
                                        'chip',
                                        'chip-estado',
                                        'chip-no-leido' => ! $correo->is_read,
                                        'chip-leido' => $correo->is_read,
                                    ])>{{ $correo->is_read ? 'Leído' : 'No leído' }}</span>
                                </td>
                                <td role="cell" data-columna="Remitente">
                                    <span class="usuario-datos">
                                        <span class="usuario-nombre" title="{{ $correo->from_email }}">{{ $correo->from_name ?? $correo->from_email }}</span>
                                        @if ($correo->from_name !== null)
                                            <span class="usuario-correo" title="{{ $correo->from_email }}">{{ $correo->from_email }}</span>
                                        @endif
                                    </span>
                                </td>
                                <td role="cell" data-columna="Fecha">
                                    @if ($correo->received_at)
                                        {{-- Convención del panel: el title es la hora del servidor (UTC, deuda NP-40). --}}
                                        <time datetime="{{ $correo->received_at->toIso8601String() }}" title="{{ $correo->received_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $correo->received_at->gt(now()->subMinute()) ? 'justo ahora' : $correo->received_at->locale('es')->diffForHumans() }}</time>
                                    @else
                                        Sin fecha
                                    @endif
                                </td>
                                <td role="cell" data-columna="Adjuntos">{{ $correo->has_attachments ? 'Sí' : 'No' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{ $correos->onEachSide(1)->links('pagination.nexus', ['etiqueta' => 'Paginación de correos recibidos']) }}
    </section>
@endsection
