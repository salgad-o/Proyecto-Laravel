{{--
    Plantilla común de los documentos legales (NP-43).
    Recibe:
      - @section('title'): título del documento (también va en <title>).
      - $etiqueta: texto pequeño sobre el título.
      - $subtitulo: frase bajo el título.
      - $parcial: vista con el texto (resources/views/legal/textos/...).
    Para cambiar un texto basta con editar su parcial; el diseño vive aquí.
--}}
@extends('layouts.app')

@section('content')
    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">{{ $etiqueta }}</p>
            <h1 class="pagina-titulo">@yield('title')</h1>
            <p class="pagina-subtitulo">{{ $subtitulo }}</p>
        </div>
    </div>

    {{-- La fecha se lee como fecha pura (sin hora), así no cambia con la zona horaria. --}}
    <p class="legal-version">
        Versión {{ config('legal.version') }} · Vigente desde
        {{ \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', config('legal.vigente_desde'))->locale('es')->translatedFormat('j \d\e F \d\e Y') }}
    </p>

    @if (config('legal.es_prueba'))
        <p class="aviso-legal-prueba" role="note">Texto de prueba: este documento todavía no es la versión oficial.</p>
    @endif

    <article class="legal-documento">
        @include($parcial)
    </article>
@endsection
