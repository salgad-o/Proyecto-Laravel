@extends('layouts.app')

@section('title', 'Noticias')

@section('content')
    <h1>Noticias</h1>

    @can('create', \App\Models\Noticia::class)
        <a href="{{ route('noticias.create') }}">Nueva noticia</a>
    @endcan

    @if (session('status'))
        <p class="status-ok">{{ session('status') }}</p>
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

                @can('update', $noticia)
                    · <a href="{{ route('noticias.edit', $noticia) }}">Editar</a>
                @endcan

                @can('delete', $noticia)
                    <form method="POST" action="{{ route('noticias.destroy', $noticia) }}" class="form-inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" data-confirm="¿Seguro que quieres eliminar esta noticia?">Eliminar</button>
                    </form>
                @endcan
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