@extends('layouts.app')

@section('title', 'Noticias')

@section('content')
    <h1>Noticias</h1>

    @auth
        <a href="{{ route('noticias.create') }}">Nueva noticia</a>
    @endauth

    @if (session('status'))
        <p style="color: green;">{{ session('status') }}</p>
    @endif

    <ul>
        @forelse ($noticias as $noticia)
            <li>
                <a href="{{ route('noticias.show', $noticia) }}">{{ $noticia->title }}</a>
                — por {{ $noticia->user->name }}
                @if ($noticia->published_at)
                    · {{ $noticia->published_at->format('d/m/Y') }}
                @endif

                @if ($noticia->excerpt)
                    <br>
                    <small>{{ $noticia->excerpt }}</small>
                @endif
            </li>
        @empty
            <li>No hay noticias publicadas todavía.</li>
        @endforelse
    </ul>

    @if ($noticias->hasPages())
        <p>
            @if ($noticias->previousPageUrl())
                <a href="{{ $noticias->previousPageUrl() }}">← Anterior</a>
            @endif

            Página {{ $noticias->currentPage() }} de {{ $noticias->lastPage() }}

            @if ($noticias->nextPageUrl())
                <a href="{{ $noticias->nextPageUrl() }}">Siguiente →</a>
            @endif
        </p>
    @endif
@endsection