@extends('layouts.app')

@section('title', 'Gestionar roles')

@section('content')
    <h1>Gestionar roles</h1>

    <p>
        Los permisos de cada rol están definidos en el código y no se pueden editar desde aquí.
        El nombre interno tampoco se puede cambiar.
    </p>

    @if (session('status'))
        <p class="status-ok">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <div class="roles-error" role="alert">
            <p>No se pudieron guardar los cambios:</p>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="roles-lista">
        @foreach ($roles as $role)
            @php
                $editando = is_string(old('form_role')) && old('form_role') === (string) $role->id;
                $labelMostrado = $editando && is_string(old('label')) ? old('label') : $role->label;
                $descriptionMostrada = $editando
                    ? (is_string(old('description')) ? old('description') : '')
                    : $role->description;
            @endphp

            <section class="formulario-tarjeta roles-tarjeta" aria-labelledby="rol-{{ $role->id }}">
                <h2 id="rol-{{ $role->id }}">{{ $role->label }}</h2>

                <p class="roles-meta">
                    Nombre interno: <strong>{{ $role->name }}</strong>.
                    No se puede cambiar.
                </p>

                <p>{{ $role->description ?? 'Sin descripción.' }}</p>

                <p class="roles-contador">
                    {{ (int) $role->users_count }} {{ (int) $role->users_count === 1 ? 'usuario' : 'usuarios' }}
                </p>

                <h3>Permisos</h3>
                <ul class="roles-permisos">
                    @forelse ($permisos[$role->name] ?? [] as $permiso)
                        <li>{{ $permiso }}</li>
                    @empty
                        <li>Sin permisos definidos.</li>
                    @endforelse
                </ul>

                <form method="POST" action="{{ route('roles.update', $role) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="form_role" value="{{ $role->id }}">

                    <div class="campo">
                        <label for="label_{{ $role->id }}">Nombre visible</label>
                        <input id="label_{{ $role->id }}" type="text" name="label"
                               value="{{ $labelMostrado }}" maxlength="50" required>
                    </div>

                    <div class="campo">
                        <label for="description_{{ $role->id }}">Descripción</label>
                        <textarea id="description_{{ $role->id }}" name="description"
                                  maxlength="255" rows="3">{{ $descriptionMostrada }}</textarea>
                    </div>

                    <button type="submit">Guardar cambios</button>
                </form>
            </section>
        @endforeach
    </div>
@endsection
