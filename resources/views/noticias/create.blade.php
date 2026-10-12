@extends('layouts.app')

@section('title', 'Nueva noticia')

@section('content')
    <h1>Nueva noticia</h1>

    <form method="POST" action="{{ route('noticias.store') }}">
        @csrf

        <label>Título</label><br>
        <input type="text" name="title" value="{{ old('title') }}" required maxlength="150">
        @error('title') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Resumen (opcional, máx. 300 caracteres)</label><br>
        <textarea name="excerpt" maxlength="300">{{ old('excerpt') }}</textarea>
        @error('excerpt') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Contenido</label><br>
        <textarea name="body" required maxlength="10000">{{ old('body') }}</textarea>
        @error('body') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Estado</label><br>
        <select name="status" required>
            <option value="borrador" @selected(old('status', 'borrador') === 'borrador')>Borrador</option>
            <option value="publicada" @selected(old('status') === 'publicada')>Publicada</option>
        </select>
        @error('status') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <button type="submit">Guardar noticia</button>
    </form>
@endsection