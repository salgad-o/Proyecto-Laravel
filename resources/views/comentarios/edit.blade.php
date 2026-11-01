@extends('layouts.app')

@section('title', 'Editar comentario')

@section('content')
    <p class="enlace-volver-linea"><a href="{{ route('noticias.show', $comentario->noticia) }}#comentarios" class="enlace-volver">← Volver a la noticia</a></p>

    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <h1 class="pagina-titulo">Editar comentario</h1>
            <p class="pagina-subtitulo">En la noticia: <a href="{{ route('noticias.show', $comentario->noticia) }}">{{ $comentario->noticia->title }}</a></p>
        </div>
    </div>

    <form method="POST" action="{{ route('comentarios.update', $comentario) }}" class="comentario-form comentario-form-edicion">
        @csrf
        @method('PUT')
        <label for="body">Comentario</label>
        <textarea id="body" name="body" rows="6" maxlength="1000" data-contador="contador-comentario" data-contador-aviso="aviso-comentario" required>{{ old('body', $comentario->body) }}</textarea>
        @error('body') <p class="error">{{ $message }}</p> @enderror
        <div class="comentario-form-pie">
            {{-- Oculto hasta que app.js lo active: sin JavaScript no se actualizaría. --}}
            <span id="contador-comentario" class="comentario-contador" hidden>{{ mb_strlen(old('body', $comentario->body)) }}/1000</span>
            <span id="aviso-comentario" class="sr-only" aria-live="polite"></span>
            <div class="comentario-form-botones">
                <a href="{{ route('noticias.show', $comentario->noticia) }}#comentarios" class="boton boton-secundario">Cancelar</a>
                <button type="submit" class="boton">Guardar cambios</button>
            </div>
        </div>
    </form>
@endsection
