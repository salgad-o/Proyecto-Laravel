@extends('layouts.app')

@section('title', 'Editar publicación')

@section('content')
    <h1>Editar publicación</h1>

    <form method="POST" action="{{ route('posts.update', $post) }}">
        @csrf
        @method('PATCH')

        <label>Título</label><br>
        <input type="text" name="title" value="{{ old('title', $post->title) }}" required maxlength="150">
        @error('title') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Contenido</label><br>
        <textarea name="body" required maxlength="10000">{{ old('body', $post->body) }}</textarea>
        @error('body') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <button type="submit">Guardar cambios</button>
    </form>
@endsection