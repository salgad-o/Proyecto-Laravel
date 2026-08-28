@extends('layouts.app')

@section('title', $post->title)

@section('content')
    <h1>{{ $post->title }}</h1>
    <p>Por {{ $post->user->name }}</p>
    <p>{{ $post->body }}</p>

    @auth
        @if (Auth::id() === $post->user_id)
            <a href="{{ route('posts.edit', $post) }}">Editar</a>

            <form method="POST" action="{{ route('posts.destroy', $post) }}" style="display:inline">
                @csrf
                @method('DELETE')
                <button type="submit" onclick="return confirm('¿Seguro que quieres eliminar este post?')">Eliminar</button>
            </form>
        @endif
    @endauth

    <p><a href="{{ route('posts.index') }}">Volver a publicaciones</a></p>
@endsection