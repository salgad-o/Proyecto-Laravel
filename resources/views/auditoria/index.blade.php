@extends('layouts.app')

@section('title', 'Auditoría')

@section('content')
    <h1>Auditoría</h1>
    <p>Registro de quién creó, editó, publicó o eliminó cada elemento. Los registros son de solo lectura.</p>

    <form method="GET" action="{{ route('auditoria.index') }}" class="filtros-auditoria">
        <label for="usuario">Usuario</label>
        <select id="usuario" name="usuario">
            <option value="">Todos</option>
            @foreach ($usuarios as $usuario)
                <option value="{{ $usuario->id }}" @selected(($filtros['usuario'] ?? null) == $usuario->id)>{{ $usuario->name }}</option>
            @endforeach
        </select>

        <label for="accion">Acción</label>
        <select id="accion" name="accion">
            <option value="">Todas</option>
            @foreach ($acciones as $clave => $etiqueta)
                <option value="{{ $clave }}" @selected(($filtros['accion'] ?? null) === $clave)>{{ $etiqueta }}</option>
            @endforeach
        </select>

        <button type="submit">Filtrar</button>
        <a href="{{ route('auditoria.index') }}">Limpiar</a>
    </form>

    @error('usuario') <p class="error">{{ $message }}</p> @enderror
    @error('accion') <p class="error">{{ $message }}</p> @enderror

    <table class="tabla-auditoria">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Usuario</th>
                <th>Acción</th>
                <th>Elemento</th>
                <th>Cambios</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($registros as $registro)
                <tr>
                    <td>{{ $registro->created_at->format('d/m/Y H:i:s') }}</td>
                    <td>{{ $registro->user?->name ?? 'Sistema' }}</td>
                    <td>{{ $registro->etiquetaAccion() }}</td>
                    <td>{{ $registro->tipoElemento() }} #{{ $registro->auditable_id }}</td>
                    <td>
                        <ul>
                            @foreach ($registro->cambios() as $campo => $valor)
                                <li>
                                    <strong>{{ $campo }}</strong>:
                                    @if ($registro->action === 'editar' || $registro->action === 'publicar')
                                        {{ \Illuminate\Support\Str::limit((string) $valor['antes'], 60) }} →
                                    @endif
                                    {{ \Illuminate\Support\Str::limit((string) ($valor['despues'] ?? $valor['antes']), 60) }}
                                </li>
                            @endforeach
                        </ul>
                    </td>
                    <td>{{ $registro->ip_address }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No hay registros de auditoría.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($registros->hasPages())
        <p>
            @if ($registros->previousPageUrl())
                <a href="{{ $registros->previousPageUrl() }}">← Anterior</a>
            @endif

            Página {{ $registros->currentPage() }} de {{ $registros->lastPage() }}

            @if ($registros->nextPageUrl())
                <a href="{{ $registros->nextPageUrl() }}">Siguiente →</a>
            @endif
        </p>
    @endif
@endsection
