@extends('layouts.app')

@section('title', 'Roles')

@section('content')
    @php
        // Rol cuyo formulario volvió con errores. Mismo criterio que antes: form_role
        // solo decide qué tarjeta abrir; el rol que se actualiza lo da la ruta {role}.
        $formRole = old('form_role');
        // Todo lo enviado, incluidas las claves con null (ConvertEmptyStringsToNull
        // convierte '' en null): así un campo enviado vacío vuelve vacío.
        $enviado = session()->getOldInput();
        $rolConError = $errors->any() && is_string($formRole) && $roles->contains(fn ($role) => $formRole === (string) $role->id)
            ? $formRole
            : null;
    @endphp

    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">PANEL DE GESTIÓN</p>
            <h1 class="pagina-titulo">Roles</h1>
            <p class="pagina-subtitulo">
                Los permisos de cada rol están definidos en el código y no se editan desde aquí. Solo puedes cambiar el nombre visible y la descripción; el nombre interno no cambia.
            </p>
        </div>
    </div>

    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    {{-- Errores que no corresponden a ninguna tarjeta (form_role ausente o manipulado):
         se muestran aquí para que nunca se pierdan. --}}
    @if ($errors->any() && $rolConError === null)
        <div class="buscador-error" role="alert">
            <p>No se pudieron guardar los cambios:</p>
            <ul class="roles-errores">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="roles-rejilla">
        @foreach ($roles as $role)
            @php
                $editando = $rolConError === (string) $role->id;
                $errorLabel = $editando && $errors->has('label');
                $errorDescription = $editando && $errors->has('description');
                // En la tarjeta que volvió con error, cada campo muestra EXACTAMENTE lo
                // enviado: se mira si la clave existe (no ?? sobre old(), que tomaría un
                // null como "no enviado"). null o un valor no textual (un arreglo) → ''.
                // El resto de tarjetas, y un campo que no se envió, muestran lo guardado.
                $mostrar = fn (string $campo, ?string $guardado) => $editando && array_key_exists($campo, $enviado)
                    ? (is_string($enviado[$campo]) ? $enviado[$campo] : '')
                    : $guardado;
                $labelMostrado = $mostrar('label', $role->label);
                $descriptionMostrada = $mostrar('description', $role->description);
                $usuarios = (int) $role->users_count;
                // Enlace al listado filtrado solo si "vista" acepta ese nombre de rol.
                $enlazable = in_array($role->name, \App\Http\Requests\IndexUsuariosRequest::VISTAS, true);
            @endphp

            <article class="rol-tarjeta" aria-labelledby="rol-{{ $role->id }}">
                <div class="rol-cabecera">
                    <h2 id="rol-{{ $role->id }}" class="rol-titulo">{{ $role->label }}</h2>
                    <span class="chip rol-chip" title="Nombre interno (no se puede cambiar)">{{ $role->name }}</span>
                </div>

                <p @class(['rol-descripcion', 'rol-descripcion-vacia' => blank($role->description)])>{{ filled($role->description) ? $role->description : 'Sin descripción.' }}</p>

                <p class="rol-contador">
                    @if ($enlazable)
                        <a href="{{ route('usuarios.index', ['vista' => $role->name]) }}">{{ $usuarios }} {{ $usuarios === 1 ? 'usuario' : 'usuarios' }}</a>
                    @else
                        {{ $usuarios }} {{ $usuarios === 1 ? 'usuario' : 'usuarios' }}
                    @endif
                </p>

                <h3 class="rol-subtitulo">Permisos</h3>
                <ul class="rol-permisos">
                    @forelse ($permisos[$role->name] ?? [] as $permiso)
                        <li>{{ $permiso }}</li>
                    @empty
                        <li>Sin permisos definidos.</li>
                    @endforelse
                </ul>

                <details class="rol-editar" @if ($editando) open @endif>
                    <summary class="boton boton-secundario boton-sm rol-editar-boton">
                        Editar<span class="sr-only"> rol {{ $role->label }}</span>
                        <svg class="rol-editar-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M6 9l6 6 6-6"></path>
                        </svg>
                    </summary>

                    <form method="POST" action="{{ route('roles.update', $role) }}" class="rol-formulario">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="form_role" value="{{ $role->id }}">

                        <div class="campo">
                            <label for="label_{{ $role->id }}">Nombre visible</label>
                            <input id="label_{{ $role->id }}" type="text" name="label"
                                   value="{{ $labelMostrado }}" maxlength="50" required
                                   @if ($errorLabel) aria-invalid="true" aria-describedby="error-label-{{ $role->id }}" @endif>
                            @if ($errorLabel)
                                <p id="error-label-{{ $role->id }}" class="buscador-error" role="alert">{{ $errors->first('label') }}</p>
                            @endif
                        </div>

                        <div class="campo">
                            <label for="description_{{ $role->id }}">Descripción</label>
                            <textarea id="description_{{ $role->id }}" name="description"
                                      maxlength="255" rows="3"
                                      @if ($errorDescription) aria-invalid="true" aria-describedby="error-description-{{ $role->id }}" @endif>{{ $descriptionMostrada }}</textarea>
                            @if ($errorDescription)
                                <p id="error-description-{{ $role->id }}" class="buscador-error" role="alert">{{ $errors->first('description') }}</p>
                            @endif
                        </div>

                        {{-- Errores de otros campos (por ejemplo form_role) que no tienen sitio propio. --}}
                        @if ($editando && ! $errorLabel && ! $errorDescription)
                            <div class="buscador-error" role="alert">
                                @foreach ($errors->all() as $error)
                                    <p>{{ $error }}</p>
                                @endforeach
                            </div>
                        @endif

                        <button type="submit" class="boton boton-sm panel-tabla-accion">Guardar cambios</button>
                    </form>
                </details>
            </article>
        @endforeach
    </div>
@endsection
