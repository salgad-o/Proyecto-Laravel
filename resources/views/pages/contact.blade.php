@extends('layouts.app')

@section('title', 'Contacto')

@section('content')
    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">CANAL DIRECTO</p>
            <h1 class="pagina-titulo">CONTACTO</h1>
            <p class="pagina-subtitulo">¿Tienes una sugerencia o una consulta sobre la revista? Cuéntanos.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('contact.send') }}" class="formulario-tarjeta">
        @csrf

        <div class="campo">
            <label for="name">Nombre</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name" @error('name') aria-invalid="true" aria-describedby="error-name" @enderror>
            @error('name') <p id="error-name" class="error">{{ $message }}</p> @enderror
        </div>

        <div class="campo">
            <label for="email">Correo electrónico</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="150" autocomplete="email" @error('email') aria-invalid="true" aria-describedby="error-email" @enderror>
            @error('email') <p id="error-email" class="error">{{ $message }}</p> @enderror
        </div>

        <div class="campo">
            <label for="message">Mensaje</label>
            <textarea id="message" name="message" rows="6" required maxlength="2000" data-contador="contador-mensaje" data-contador-aviso="aviso-mensaje" @error('message') aria-invalid="true" aria-describedby="error-message" @enderror>{{ old('message') }}</textarea>
            @error('message') <p id="error-message" class="error">{{ $message }}</p> @enderror
            <div class="campo-pie">
                {{-- Oculto hasta que app.js lo active: sin JavaScript no se actualizaría. --}}
                <span id="contador-mensaje" class="comentario-contador" hidden>{{ mb_strlen(old('message', '')) }}/2000</span>
                <span id="aviso-mensaje" class="sr-only" aria-live="polite"></span>
            </div>
        </div>

        <button type="submit" class="boton">Enviar mensaje</button>

        <p class="formulario-nota">Por ahora este formulario solo valida tu mensaje: no lo guarda ni lo envía.</p>
    </form>
@endsection
