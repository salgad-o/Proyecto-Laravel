@extends('layouts.app')

@section('title', 'Editar comentario')

@section('content')
    <h1>Editar comentario</h1>
    <p>En la noticia: <a href="{{ route('noticias.show', $comentario->noticia) }}">{{ $comentario->noticia->title }}</a></p>

    <form method="POST" action="{{ route('comentarios.update', $comentario) }}" class="comentario-form">
        @csrf
        @method('PUT')
        <label for="body">Comentario</label>
        <textarea id="body" name="body" rows="4" maxlength="1000" required>{{ old('body', $comentario->body) }}</textarea>
        @error('body') <p class="error">{{ $message }}</p> @enderror
        <button type="submit">Guardar cambios</button>
        <a href="{{ route('noticias.show', $comentario->noticia) }}#comentarios">Cancelar</a>
    </form>
@endsection
