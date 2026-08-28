@extends('layouts.app')

@section('title', 'Publicaciones')

@section('content')
    <h1>Publicaciones</h1>

    <a href="{{ route('posts.create') }}">Nueva publicación</a>

    @if (session('status'))
        <p style="color: green;">{{ session('status') }}</p>
    @endif

    <ul>
        @forelse ($posts as $post)
            <li>
                <a href="{{ route('posts.show', $post) }}">{{ $post->title }}</a>
                — por {{ $post->user->name }}
            </li>
        @empty
            <li>No hay publicaciones todavía.</li>
        @endforelse
    </ul>
@endsection