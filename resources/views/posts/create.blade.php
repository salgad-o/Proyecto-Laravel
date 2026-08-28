@extends('layouts.app')

@section('title', 'Nueva publicación')

@section('content')
    <h1>Nueva publicación</h1>

    <form method="POST" action="{{ route('posts.store') }}">
        @csrf

        <label>Título</label><br>
        <input type="text" name="title" value="{{ old('title') }}" required maxlength="150">
        @error('title') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <label>Contenido</label><br>
        <textarea name="body" required maxlength="10000">{{ old('body') }}</textarea>
        @error('body') <p style="color: red;">{{ $message }}</p> @enderror
        <br><br>

        <button type="submit">Publicar</button>
    </form>
@endsection