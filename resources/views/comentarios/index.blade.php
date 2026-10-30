@extends('layouts.app')

@section('title', 'Moderar comentarios')

@section('content')
    <h1>Moderar comentarios</h1>

    @if (session('status'))
        <p class="status-ok">{{ session('status') }}</p>
    @endif

    <table class="tabla-moderacion">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Autor</th>
                <th>Noticia</th>
                <th>Comentario</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($comentarios as $comentario)
                <tr>
                    <td>{{ $comentario->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $comentario->user->name }}</td>
                    <td><a href="{{ route('noticias.show', $comentario->noticia) }}#comentarios">{{ $comentario->noticia->title }}</a></td>
                    <td>{{ \Illuminate\Support\Str::limit($comentario->body, 120) }}</td>
                    <td>
                        <form method="POST" action="{{ route('comentarios.destroy', $comentario) }}" class="form-inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" data-confirm="¿Eliminar este comentario?">Eliminar</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No hay comentarios.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($comentarios->hasPages())
        <p>
            @if ($comentarios->previousPageUrl())
                <a href="{{ $comentarios->previousPageUrl() }}">← Anterior</a>
            @endif

            Página {{ $comentarios->currentPage() }} de {{ $comentarios->lastPage() }}

            @if ($comentarios->nextPageUrl())
                <a href="{{ $comentarios->nextPageUrl() }}">Siguiente →</a>
            @endif
        </p>
    @endif
@endsection
