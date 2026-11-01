@extends('layouts.app')

@section('title', 'Administrar usuarios')

@section('content')
    <h1>Administrar usuarios</h1>

    @if (session('status'))
        <p class="status-ok">{{ session('status') }}</p>
    @endif

    <form method="GET" action="{{ route('usuarios.index') }}" class="filtros-auditoria">
        <label for="buscar">Buscar</label>
        <input type="search" id="buscar" name="buscar" value="{{ $buscar }}" maxlength="100" placeholder="Nombre o correo">

        <button type="submit">Buscar</button>
        <a href="{{ route('usuarios.index') }}">Limpiar</a>
    </form>

    @error('buscar') <p class="error">{{ $message }}</p> @enderror
    @error('role_id') <p class="error">{{ $message }}</p> @enderror
    @error('is_active') <p class="error">{{ $message }}</p> @enderror

    <table class="tabla-moderacion">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Registro</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($usuarios as $usuario)
                <tr>
                    <td>{{ $usuario->name }}</td>
                    <td>{{ $usuario->email }}</td>
                    <td>{{ $usuario->role?->label }}</td>
                    <td>{{ $usuario->is_active ? 'Activo' : 'Inactivo' }}</td>
                    <td>{{ $usuario->created_at?->format('d/m/Y') }}</td>
                    <td>
                        @if ($usuario->is(auth()->user()))
                            Tu cuenta
                        @else
                            @can('updateRole', $usuario)
                                <form method="POST" action="{{ route('usuarios.rol', $usuario) }}" class="form-inline">
                                    @csrf
                                    @method('PATCH')
                                    <label for="role_id_{{ $usuario->id }}">Rol</label>
                                    <select id="role_id_{{ $usuario->id }}" name="role_id">
                                        @foreach ($roles as $id => $etiqueta)
                                            <option value="{{ $id }}" @selected($usuario->role_id == $id)>{{ $etiqueta }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit">Cambiar rol</button>
                                </form>
                            @endcan

                            @can('updateStatus', $usuario)
                                <form method="POST" action="{{ route('usuarios.estado', $usuario) }}" class="form-inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $usuario->is_active ? 0 : 1 }}">
                                    <button type="submit" data-confirm="¿Seguro que deseas {{ $usuario->is_active ? 'desactivar' : 'activar' }} a {{ $usuario->name }}?">{{ $usuario->is_active ? 'Desactivar' : 'Activar' }}</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No se encontraron usuarios.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($usuarios->hasPages())
        <p>
            @if ($usuarios->previousPageUrl())
                <a href="{{ $usuarios->previousPageUrl() }}">← Anterior</a>
            @endif

            Página {{ $usuarios->currentPage() }} de {{ $usuarios->lastPage() }}

            @if ($usuarios->nextPageUrl())
                <a href="{{ $usuarios->nextPageUrl() }}">Siguiente →</a>
            @endif
        </p>
    @endif
@endsection
