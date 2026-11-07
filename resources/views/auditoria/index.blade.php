@extends('layouts.app')

@section('title', 'Auditoría')

@section('content')
    @php
        $hayFiltros = $filtros !== [];
    @endphp

    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">PANEL DE GESTIÓN</p>
            <h1 id="auditoria-titulo" class="pagina-titulo">Auditoría</h1>
            <p class="pagina-subtitulo">
                Quién creó, editó, publicó o eliminó noticias, comentarios, usuarios y roles; quién cambió el rol de un usuario o activó o desactivó su cuenta; y los cambios de contraseña, cierres de otras sesiones y cambios de foto de perfil.
            </p>
        </div>
    </div>

    <p class="auditoria-aviso">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="4" y="11" width="16" height="10" rx="2"></rect>
            <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
        </svg>
        Solo lectura: los registros no se pueden modificar ni eliminar.
    </p>

    {{-- Filtros en una fila (se apila en pantallas estrechas). Los valida AuditoriaRequest. --}}
    <form method="GET" action="{{ route('auditoria.index') }}" class="auditoria-filtros">
        <div class="auditoria-filtro">
            <label for="filtro-importancia">Importancia</label>
            <select id="filtro-importancia" name="importancia">
                <option value="">Todas</option>
                @foreach ($niveles as $clave => $etiqueta)
                    <option value="{{ $clave }}" @selected(($filtros['importancia'] ?? null) === $clave)>{{ $etiqueta }}</option>
                @endforeach
            </select>
        </div>

        <div class="auditoria-filtro">
            <label for="usuario">Usuario</label>
            <select id="usuario" name="usuario">
                <option value="">Todos</option>
                @foreach ($usuarios as $usuario)
                    <option value="{{ $usuario->id }}" @selected(($filtros['usuario'] ?? null) == $usuario->id)>{{ $usuario->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="auditoria-filtro">
            <label for="accion">Acción</label>
            <select id="accion" name="accion">
                <option value="">Todas</option>
                @foreach ($acciones as $clave => $etiqueta)
                    <option value="{{ $clave }}" @selected(($filtros['accion'] ?? null) === $clave)>{{ $etiqueta }}</option>
                @endforeach
            </select>
        </div>

        <div class="auditoria-filtros-acciones">
            <button type="submit" class="boton">Filtrar</button>
            @if ($hayFiltros)
                <a href="{{ route('auditoria.index') }}" class="buscador-noticias-limpiar">Limpiar</a>
            @endif
        </div>
    </form>

    @foreach (['importancia', 'usuario', 'accion'] as $campo)
        @error($campo)
            <p class="buscador-error" role="alert">{{ $message }}</p>
        @enderror
    @endforeach

    <section aria-labelledby="auditoria-titulo" class="panel-auditoria">
        @if ($registros->isEmpty())
            <div class="estado-vacio">
                <p class="estado-vacio-texto">
                    @if ($hayFiltros)
                        No hay registros que coincidan con el filtro.
                    @else
                        Aún no hay registros de auditoría.
                    @endif
                </p>
                @if ($hayFiltros)
                    <a href="{{ route('auditoria.index') }}" class="boton boton-secundario">Limpiar filtros</a>
                @endif
            </div>
        @else
            {{-- Misma tabla que Redacción: container query, roles ARIA para el modo apilado
                 y contenedor con scroll de seguridad. La IP y el navegador van plegados. --}}
            <div class="panel-tabla-contenedor">
                <table class="panel-tabla" role="table">
                    <caption class="sr-only">Auditoría: registros, página {{ $registros->currentPage() }} de {{ $registros->lastPage() }}</caption>
                    <thead role="rowgroup">
                        <tr role="row">
                            <th scope="col" role="columnheader">Importancia</th>
                            <th scope="col" role="columnheader">Fecha</th>
                            <th scope="col" role="columnheader">Usuario</th>
                            <th scope="col" role="columnheader">Acción</th>
                            <th scope="col" role="columnheader">Elemento</th>
                            <th scope="col" role="columnheader">Detalles</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup">
                        @foreach ($registros as $registro)
                            @php
                                $lineas = $registro->cambiosLegibles($roles);
                                // Plegado si hay más de 2 cambios o algún valor no cabe entero.
                                $plegar = count($lineas) > 2 || collect($lineas)->contains('largo', true);
                            @endphp
                            <tr role="row">
                                <td role="cell" data-columna="Importancia">
                                    <span @class([
                                        'chip',
                                        'chip-importancia',
                                        'chip-alta' => $registro->importancia() === 'alta',
                                        'chip-media' => $registro->importancia() === 'media',
                                        'chip-baja' => $registro->importancia() === 'baja',
                                    ])>{{ $registro->etiquetaImportancia() }}</span>
                                </td>
                                <td role="cell" data-columna="Fecha">
                                    {{-- Misma convención que el panel: el title es la hora del servidor (UTC, deuda NP-40). --}}
                                    <time datetime="{{ $registro->created_at->toIso8601String() }}" title="{{ $registro->created_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $registro->created_at->gt(now()->subMinute()) ? 'justo ahora' : $registro->created_at->locale('es')->diffForHumans() }}</time>
                                </td>
                                <td role="cell" data-columna="Usuario">
                                    @if ($registro->user)
                                        <span class="panel-tabla-autor auditoria-usuario">
                                            <x-avatar :usuario="$registro->user" :tamano="28" clase="autor-avatar panel-tabla-avatar" :lazy="true" />
                                            <span>{{ $registro->user->name }}</span>
                                        </span>
                                    @else
                                        <span class="auditoria-sistema">Sistema</span>
                                    @endif
                                </td>
                                <th scope="row" role="rowheader" class="auditoria-accion">{{ $registro->etiquetaAccion() }}</th>
                                <td role="cell" data-columna="Elemento">{{ $registro->tipoElemento() }} #{{ $registro->auditable_id }}</td>
                                <td role="cell" data-columna="Detalles" class="auditoria-detalles">
                                    @if ($lineas === [])
                                        <span class="auditoria-sin-cambios">{{ $registro->textoSinValores() }}</span>
                                    @elseif (! $plegar)
                                        <ul class="auditoria-cambios">
                                            @foreach ($lineas as $linea)
                                                <li><strong>{{ $linea['campo'] }}</strong>: @if ($linea['antes'] !== null){{ $linea['antes'] }} → @endif{{ $linea['despues'] }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        {{-- Resumen en una línea: el primer cambio y cuántos más hay. --}}
                                        <p class="auditoria-resumen"><strong>{{ $lineas[0]['campo'] }}</strong>: @if ($lineas[0]['antes'] !== null){{ $lineas[0]['antes'] }} → @endif{{ $lineas[0]['despues'] }}@if (count($lineas) > 1) <span class="auditoria-mas">y {{ count($lineas) - 1 }} más</span>@endif</p>
                                    @endif

                                    <details class="auditoria-plegable">
                                        <summary>{{ $plegar ? 'Ver '.count($lineas).' cambios' : 'Ver origen' }}</summary>
                                        @if ($plegar)
                                            <ul class="auditoria-cambios">
                                                @foreach ($registro->cambiosLegibles($roles, 160) as $linea)
                                                    <li><strong>{{ $linea['campo'] }}</strong>: @if ($linea['antes'] !== null){{ $linea['antes'] }} → @endif{{ $linea['despues'] }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        <dl class="auditoria-origen">
                                            <div>
                                                <dt>IP</dt>
                                                <dd>{{ $registro->ip_address ?? 'Desconocida' }}</dd>
                                            </div>
                                            <div>
                                                <dt>Navegador</dt>
                                                {{-- Solo el navegador interpretado: el user_agent crudo se guarda pero no se muestra. --}}
                                                <dd>{{ $registro->navegadorLegible() }}</dd>
                                            </div>
                                        </dl>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{ $registros->onEachSide(1)->links('pagination.nexus', ['etiqueta' => 'Paginación de la auditoría']) }}
    </section>
@endsection
