@extends('layouts.app')

@section('title', $noticia->title)

@section('content')
    @if (session('aviso_noticia') && session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    <h1>{{ $noticia->title }}</h1>

    @if ($noticia->status === 'borrador')
        <p class="aviso-borrador"><strong>Borrador:</strong> solo tú puedes ver esta noticia.</p>
    @endif

    <p>
        Por {{ $noticia->user->name }}
        @if ($noticia->published_at)
            · {{ $noticia->published_at->format('d/m/Y') }}
        @endif
    </p>

    @if ($noticia->excerpt)
        <p><em>{{ $noticia->excerpt }}</em></p>
    @endif

    <p class="texto-preformateado">{{ $noticia->body }}</p>

    @can('update', $noticia)
        <a href="{{ route('noticias.edit', $noticia) }}">Editar</a>
    @endcan

    @can('delete', $noticia)
        <form method="POST" action="{{ route('noticias.destroy', $noticia) }}" class="form-inline">
            @csrf
            @method('DELETE')
            <button type="submit" data-confirm="¿Seguro que quieres eliminar esta noticia?">Eliminar</button>
        </form>
    @endcan

    <p><a href="{{ route('noticias.index') }}">Volver a noticias</a></p>

    @include('comentarios._seccion', ['noticia' => $noticia])
@endsection