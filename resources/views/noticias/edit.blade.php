@extends('layouts.app')

@section('title', 'Editar noticia')

@section('content')
    <h1>Editar noticia</h1>

    <form method="POST" action="{{ route('noticias.update', $noticia) }}" enctype="multipart/form-data">
        @csrf
        @method('PATCH')

        <label>Título</label><br>
        <input type="text" name="title" value="{{ old('title', $noticia->title) }}" required maxlength="150" class="form-input">
        @error('title') <p class="error">{{ $message }}</p> @enderror
        <br><br>

        <label>Resumen (opcional, máx. 300 caracteres)</label><br>
        <textarea name="excerpt" maxlength="300" class="form-input">{{ old('excerpt', $noticia->excerpt) }}</textarea>
        @error('excerpt') <p class="error">{{ $message }}</p> @enderror
        <br><br>

        <label>Contenido</label><br>
        <textarea name="body" required maxlength="10000" class="form-input">{{ old('body', $noticia->body) }}</textarea>
        @error('body') <p class="error">{{ $message }}</p> @enderror
        <br><br>

        <label>Portada (opcional)</label><br>
        <input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp" class="boton-subir">
        @error('cover_image')
            <p class="error">{{ $message }}</p>
        @enderror
        <br><br>

        @if ($noticia->cover_image)
            <div class="portada-preview">
                <img src="{{ $noticia->cover_image_url }}"
                     alt=""
                     width="1600" height="900" loading="lazy" class="img-portada">
                <p class="file-name">Portada actual: {{ basename($noticia->cover_image) }}</p>
            </div>

            <div class="portada-check">
                <input type="checkbox" id="quitar_portada" name="quitar_portada" value="1" @checked(old('quitar_portada'))>
                <label for="quitar_portada">Quitar portada</label>
            </div>
            @error('quitar_portada') <p class="error">{{ $message }}</p> @enderror
            <br>
        @endif

        <label>Estado</label><br>
        <select name="status" required>
            <option value="borrador" @selected(old('status', $noticia->status) === 'borrador')>Borrador</option>
            <option value="publicada" @selected(old('status', $noticia->status) === 'publicada')>Publicada</option>
        </select>
        @error('status') <p class="error">{{ $message }}</p> @enderror
        <br><br>

        <button type="submit" class="boton">Guardar cambios</button>
    </form>
@endsection
