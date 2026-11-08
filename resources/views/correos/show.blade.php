@extends('layouts.app')

{{-- Título fijo: el asunto lo escribió el remitente y no debe quedar en el historial del navegador. --}}
@section('title', 'Correo recibido')

@section('content')
    <p class="enlace-volver-linea">
        <a href="{{ route('correos.index', $volver) }}" class="enlace-volver">← Volver a correos recibidos</a>
    </p>

    @if (session('status'))
        <p class="aviso-exito" role="status">{{ session('status') }}</p>
    @endif

    @error('leido')
        <p class="buscador-error" role="alert">{{ $message }}</p>
    @enderror

    <article class="correo-detalle" aria-labelledby="correo-asunto">
        <div class="pagina-encabezado">
            <div class="pagina-encabezado-texto">
                <p class="pagina-etiqueta">CORREO RECIBIDO</p>
                <h1 id="correo-asunto" class="pagina-titulo correo-titulo">{{ $correo->subject ?? '(sin asunto)' }}</h1>
            </div>
        </div>

        <dl class="correo-meta">
            <div class="correo-meta-fila">
                <dt>De</dt>
                <dd>
                    @if ($correo->from_name !== null)
                        {{ $correo->from_name }} &lt;{{ $correo->from_email }}&gt;
                    @else
                        {{ $correo->from_email }}
                    @endif
                </dd>
            </div>
            <div class="correo-meta-fila">
                <dt>Fecha</dt>
                <dd>
                    @if ($correo->received_at)
                        <time datetime="{{ $correo->received_at->toIso8601String() }}">{{ $correo->received_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }} (UTC)</time>
                    @else
                        Sin fecha
                    @endif
                </dd>
            </div>
            <div class="correo-meta-fila">
                <dt>Estado</dt>
                <dd>
                    <span @class([
                        'chip',
                        'chip-estado',
                        'chip-no-leido' => ! $correo->is_read,
                        'chip-leido' => $correo->is_read,
                    ])>{{ $correo->is_read ? 'Leído' : 'No leído' }}</span>
                </dd>
            </div>
            @if ($correo->has_attachments)
                <div class="correo-meta-fila">
                    <dt>Adjuntos</dt>
                    <dd>Tenía adjuntos; no se guardan ni se pueden descargar.</dd>
                </div>
            @endif
        </dl>

        <form method="POST" action="{{ route('correos.marcar', $correo) }}" class="correo-acciones">
            @csrf
            @method('PATCH')
            <input type="hidden" name="leido" value="{{ $correo->is_read ? 0 : 1 }}">
            @foreach ($volver as $nombre => $valor)
                <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
            @endforeach
            <button type="submit" class="boton boton-secundario boton-sm">
                {{ $correo->is_read ? 'Marcar como no leído' : 'Marcar como leído' }}
            </button>
        </form>

        @if ($constancia)
            <section class="correo-constancia" aria-labelledby="correo-constancia-titulo">
                <h2 id="correo-constancia-titulo" class="correo-seccion-titulo">Constancia de autorización</h2>
                <p>
                    El remitente autorizó el tratamiento de sus datos el
                    <time datetime="{{ $constancia->privacy_accepted_at->toIso8601String() }}">{{ $constancia->privacy_accepted_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }} (UTC)</time>,
                    con la política versión {{ $constancia->legal_version }}.
                </p>
            </section>
        @endif

        <section aria-labelledby="correo-cuerpo-titulo">
            <h2 id="correo-cuerpo-titulo" class="correo-seccion-titulo">Mensaje</h2>
            @if ($correo->body !== null)
                {{-- Texto plano escapado con {{ }}: nunca HTML, y las URL no se convierten en enlaces. --}}
                <div class="texto-preformateado correo-cuerpo">{{ $correo->body }}</div>
            @else
                <p class="correo-sin-texto">El correo no tiene texto.</p>
            @endif
        </section>
    </article>
@endsection
