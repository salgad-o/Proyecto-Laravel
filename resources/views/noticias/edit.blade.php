@extends('layouts.app')

@section('title', 'Editar noticia')

@section('content')
    <h1>Editar noticia</h1>

    <form method="POST" action="{{ route('noticias.update', $noticia) }}">
        @csrf
        @method('PATCH')

        <label>Título</label><br>
        <input type="text" name="title" value="{{ old('title', $noticia->title) }}" required maxlength="150">
        @error('title') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Resumen (opcional, máx. 300 caracteres)</label><br>
        <textarea name="excerpt" maxlength="300">{{ old('excerpt', $noticia->excerpt) }}</textarea>
        @error('excerpt') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Contenido</label><br>
        <textarea name="body" required maxlength="10000">{{ old('body', $noticia->body) }}</textarea>
        @error('body') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Estado</label><br>
        <select name="status" required>
            <option value="borrador" @selected(old('status', $noticia->status) === 'borrador')>Borrador</option>
            <option value="publicada" @selected(old('status', $noticia->status) === 'publicada')>Publicada</option>
        </select>
        @error('status') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <button type="submit">Guardar cambios</button>
    </form>
@endsection