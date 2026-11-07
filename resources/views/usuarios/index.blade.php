@extends('layouts.app')

@section('title', 'Usuarios')

@section('content')
    @php
        $buscarMostrado = is_string(old('buscar')) ? old('buscar') : ($buscar ?? '');
        $hayFiltros = $buscarMostrado !== '' || $vista !== 'todos';
        $pestanas = [
            'todos' => 'Todos',
            'admin' => 'Admins',
            'editor' => 'Editores',
            'lector' => 'Lectores',
            'inactivos' => 'Inactivos',
        ];

        // Fila que se abre tras un error de validación al cambiar rol o estado.
        // form_usuario SOLO decide qué fila de panel abrir: el usuario al que se aplica el
        // cambio lo dan la ruta {usuario} y la UserPolicy, nunca este campo.
        $formUsuario = old('form_usuario');
        $filaConError = is_string($formUsuario) && ctype_digit($formUsuario) ? $formUsuario : null;
        $hayErrorDeGestion = $errors->has('role_id') || $errors->has('is_active');
        $errorEnUnaFila = $hayErrorDeGestion && $filaConError !== null && $usuarios->contains(
            fn ($usuario) => (string) $usuario->id === $filaConError
                && (auth()->user()->can('updateRole', $usuario) || auth()->user()->can('updateStatus', $usuario))
        );
    @endphp

    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">PANEL DE GESTIÓN</p>
            <h1 id="usuarios-titulo" class="pagina-titulo">Usuarios</h1>
            <p class="pagina-subtitulo">
                Cambia roles y activa o desactiva cuentas. Los usuarios nunca se borran, y tu propia cuenta no se puede modificar desde aquí.
            </p>
        </div>
    </div>

    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    {{-- Si el error no corresponde a ninguna fila visible (campo oculto manipulado o la
         fila está en otra página), se muestra aquí para que nunca se pierda. --}}
    @if ($hayErrorDeGestion && ! $errorEnUnaFila)
        <div class="buscador-error" role="alert">
            @foreach (['role_id', 'is_active'] as $campo)
                @error($campo)
                    <p>{{ $message }}</p>
                @enderror
            @endforeach
        </div>
    @endif

    {{-- Contadores: misma búsqueda que la lista, sin el filtro de la pestaña.
         El número visible "(N)" se oculta al lector, que oye "Editores, 2 usuarios". --}}
    <nav class="panel-estado-tabs" aria-label="Filtrar usuarios">
        @foreach ($pestanas as $valor => $texto)
            <a href="{{ route('usuarios.index', array_filter(['buscar' => $buscarMostrado, 'vista' => $valor === 'todos' ? null : $valor], fn ($dato) => $dato !== null && $dato !== '')) }}"
               @class(['panel-estado-tab', 'panel-estado-activo' => $vista === $valor])
               @if ($vista === $valor) aria-current="page" @endif>
                {{ $texto }} <span class="panel-estado-total" aria-hidden="true">({{ $contadores[$valor] }})</span><span class="sr-only">, {{ $contadores[$valor] }} {{ $contadores[$valor] === 1 ? 'usuario' : 'usuarios' }}</span>
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('usuarios.index') }}" class="buscador-noticias" role="search">
        <div class="buscador-noticias-campo">
            <label for="buscar">Buscar usuarios</label>
            <input type="search" id="buscar" name="buscar" value="{{ $buscarMostrado }}" maxlength="100" placeholder="Nombre o correo">
            @error('buscar')
                <p class="buscador-error" role="alert">{{ $message }}</p>
            @enderror
            @error('vista')
                <p class="buscador-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        @if ($vista !== 'todos')
            <input type="hidden" name="vista" value="{{ $vista }}">
        @endif

        <div class="buscador-noticias-acciones">
            <button type="submit" class="boton">Buscar</button>
            @if ($hayFiltros)
                <a href="{{ route('usuarios.index') }}" class="buscador-noticias-limpiar">Limpiar</a>
            @endif
        </div>
    </form>

    <section aria-labelledby="usuarios-titulo" class="panel-usuarios">
        @if ($usuarios->isEmpty())
            <div class="estado-vacio">
                <p class="estado-vacio-texto">
                    @if ($hayFiltros)
                        No se encontraron usuarios.
                    @else
                        Aún no hay usuarios registrados.
                    @endif
                </p>
                @if ($hayFiltros)
                    <a href="{{ route('usuarios.index') }}" class="boton boton-secundario">Limpiar filtros</a>
                @endif
            </div>
        @else
            {{-- Misma tabla que Redacción y Auditoría: container query, roles ARIA para
                 el modo apilado y contenedor con scroll de seguridad. --}}
            <div class="panel-tabla-contenedor">
                <table class="panel-tabla" role="table">
                    <caption class="sr-only">Usuarios ({{ $pestanas[$vista] }}): página {{ $usuarios->currentPage() }} de {{ $usuarios->lastPage() }}</caption>
                    <thead role="rowgroup">
                        <tr role="row">
                            <th scope="col" role="columnheader">Usuario</th>
                            <th scope="col" role="columnheader">Rol</th>
                            <th scope="col" role="columnheader">Estado</th>
                            <th scope="col" role="columnheader">Registro</th>
                            <th scope="col" role="columnheader">Acciones</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup">
                        @foreach ($usuarios as $usuario)
                            @php
                                $abierta = $errorEnUnaFila && $filaConError === (string) $usuario->id;
                                $errorRol = $abierta && $errors->has('role_id');
                                $errorEstado = $abierta && $errors->has('is_active');
                                // Las Policies deciden si hay acciones. Hoy solo se niegan
                                // sobre la propia cuenta (autoprotección del admin).
                                $gestionable = auth()->user()->can('updateRole', $usuario) || auth()->user()->can('updateStatus', $usuario);
                                $idPanel = 'gestion-usuario-'.$usuario->id;
                            @endphp
                            <tr role="row">
                                <th scope="row" role="rowheader" class="usuario-celda">
                                    <span class="usuario-identidad">
                                        <x-avatar :usuario="$usuario" :tamano="28" clase="autor-avatar panel-tabla-avatar" :lazy="true" />
                                        <span class="usuario-datos">
                                            {{-- title: valor completo si el CSS lo corta con "…". --}}
                                            <span class="usuario-nombre" title="{{ $usuario->name }}">{{ $usuario->name }}</span>
                                            <span class="usuario-correo" title="{{ $usuario->email }}">{{ $usuario->email }}</span>
                                        </span>
                                    </span>
                                </th>
                                <td role="cell" data-columna="Rol">{{ $usuario->role?->label }}</td>
                                <td role="cell" data-columna="Estado">
                                    <span @class([
                                        'chip',
                                        'chip-estado',
                                        'chip-activo' => $usuario->is_active,
                                        'chip-inactivo' => ! $usuario->is_active,
                                    ])>{{ $usuario->is_active ? 'Activo' : 'Inactivo' }}</span>
                                </td>
                                <td role="cell" data-columna="Registro">{{ $usuario->created_at?->format('d/m/Y') }}</td>
                                <td role="cell" class="panel-tabla-acciones-celda">
                                    @if ($gestionable)
                                        {{-- Patrón "disclosure": el botón muestra u oculta la fila de panel de
                                             abajo (aria-controls). Sin JS el botón no aparece y el panel ya
                                             está visible; app.js lo pliega y activa el botón. aria-expanded
                                             "true" solo en la fila que vuelve con un error de validación. --}}
                                        <button type="button" class="boton boton-secundario boton-sm usuario-gestionar-boton"
                                                aria-expanded="{{ $abierta ? 'true' : 'false' }}" aria-controls="{{ $idPanel }}" data-gestionar>
                                            Gestionar<span class="sr-only"> a {{ $usuario->name }}</span>
                                            <svg class="usuario-gestionar-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </button>
                                    @else
                                        <span class="usuario-sin-acciones">{{ $usuario->is(auth()->user()) ? 'Tu cuenta' : 'Sin acciones' }}</span>
                                    @endif
                                </td>
                            </tr>
                            @if ($gestionable)
                                {{-- Fila de panel: una sola celda a todo el ancho, justo debajo de su usuario. --}}
                                <tr role="row" id="{{ $idPanel }}" class="usuario-panel-fila">
                                    <td role="cell" colspan="5" class="usuario-panel-celda">
                                        <p class="sr-only">Gestionar a {{ $usuario->name }}</p>
                                        <div class="usuario-gestion">
                                            @can('updateRole', $usuario)
                                                <form method="POST" action="{{ route('usuarios.rol', $usuario) }}" class="usuario-gestion-bloque">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="form_usuario" value="{{ $usuario->id }}">
                                                    <p class="usuario-gestion-titulo">Cambiar rol</p>
                                                    <label for="role_id_{{ $usuario->id }}">Rol</label>
                                                    <div class="usuario-gestion-fila">
                                                        <select id="role_id_{{ $usuario->id }}" name="role_id" @if ($errorRol) aria-invalid="true" aria-describedby="error-rol-{{ $usuario->id }}" @endif>
                                                            @foreach ($roles as $id => $etiqueta)
                                                                <option value="{{ $id }}" @selected($usuario->role_id == $id)>{{ $etiqueta }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="submit" class="boton boton-sm panel-tabla-accion">Guardar rol</button>
                                                    </div>
                                                    @if ($errorRol)
                                                        <p id="error-rol-{{ $usuario->id }}" class="buscador-error" role="alert">{{ $errors->first('role_id') }}</p>
                                                    @endif
                                                </form>
                                            @endcan

                                            @can('updateStatus', $usuario)
                                                <form method="POST" action="{{ route('usuarios.estado', $usuario) }}" class="usuario-gestion-bloque">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="form_usuario" value="{{ $usuario->id }}">
                                                    <input type="hidden" name="is_active" value="{{ $usuario->is_active ? 0 : 1 }}">
                                                    <p class="usuario-gestion-titulo">Cuenta</p>
                                                    <p class="usuario-gestion-texto">{{ $usuario->is_active ? 'La cuenta está activa.' : 'La cuenta está desactivada: no puede iniciar sesión.' }}</p>
                                                    <button type="submit"
                                                            @class(['boton', 'boton-sm', 'panel-tabla-accion', 'boton-peligro' => $usuario->is_active])
                                                            data-confirm="¿Seguro que deseas {{ $usuario->is_active ? 'desactivar' : 'activar' }} a {{ $usuario->name }}?"
                                                            @if ($errorEstado) aria-describedby="error-estado-{{ $usuario->id }}" @endif>
                                                        {{ $usuario->is_active ? 'Desactivar cuenta' : 'Activar cuenta' }}
                                                    </button>
                                                    @if ($errorEstado)
                                                        <p id="error-estado-{{ $usuario->id }}" class="buscador-error" role="alert">{{ $errors->first('is_active') }}</p>
                                                    @endif
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{ $usuarios->onEachSide(1)->links('pagination.nexus', ['etiqueta' => 'Paginación de usuarios']) }}
    </section>
@endsection
