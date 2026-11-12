@extends('layouts.app')

@section('title', 'Nueva noticia')

@section('content')
    <h1>Nueva noticia</h1>

    <form method="POST" action="{{ route('noticias.store') }}" enctype="multipart/form-data">
        @csrf

        <label>Título</label><br>
        <input type="text" name="title" value="{{ old('title') }}" required maxlength="150" class="form-input">
        @error('title') <p class="error">{{ $message }}</p> @enderror
        <br><br>

        <label>Resumen (opcional, máx. 300 caracteres)</label><br>
        <textarea name="excerpt" maxlength="300" class="form-input">{{ old('excerpt') }}</textarea>
        @error('excerpt') <p class="error">{{ $message }}</p> @enderror
        <br><br>

        <label>Contenido</label><br>
        <textarea name="body" required maxlength="10000" class="form-input">{{ old('body') }}</textarea>
        @error('body') <p class="error">{{ $message }}</p> @enderror
        <br><br>

        <label>Portada (opcional)</label><br>
        <input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp" class="boton-subir">
        @error('cover_image')
            <p class="error">{{ $message }}</p>
        @enderror
        <br><br>

        <label>Estado</label><br>
        <select name="status" required>
            <option value="borrador" @selected(old('status', 'borrador') === 'borrador')>Borrador</option>
            <option value="publicada" @selected(old('status') === 'publicada')>Publicada</option>
        </select>
        @error('status') <p class="error">{{ $message }}</p> @enderror
        <br><br>

        <button type="submit" class="boton">Guardar noticia</button>
    </form>
@endsection
