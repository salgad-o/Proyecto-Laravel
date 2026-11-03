@extends('layouts.app')

@section('title', 'Acerca')

@section('content')
    <div class="pagina-encabezado">
        <div class="pagina-encabezado-texto">
            <p class="pagina-etiqueta">SOBRE NOSOTROS</p>
            <h1 class="pagina-titulo">ACERCA DE NEXUS PLAYS</h1>
            <p class="pagina-subtitulo">Revista digital independiente de videojuegos, construida con prácticas de desarrollo seguro.</p>
        </div>
    </div>

    <div class="valores-rejilla">
        <article class="valor-tarjeta">
            <span class="valor-icono">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                    <path d="M7 9h10"></path>
                    <path d="M7 13h10"></path>
                    <path d="M7 17h6"></path>
                </svg>
            </span>
            <h2 class="valor-titulo">Periodismo independiente</h2>
            <p class="valor-texto">Análisis y noticias sin publicidad encubierta.</p>
        </article>

        <article class="valor-tarjeta">
            <span class="valor-icono">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"></path>
                    <path d="M9 12l2 2 4-4"></path>
                </svg>
            </span>
            <h2 class="valor-titulo">Desarrollo seguro</h2>
            <p class="valor-texto">Contraseñas con hash, protección CSRF en los formularios, Content Security Policy, control de acceso por roles y auditoría de cambios.</p>
        </article>

        <article class="valor-tarjeta">
            <span class="valor-icono">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
            </span>
            <h2 class="valor-titulo">Comunidad</h2>
            <p class="valor-texto">La administración puede retirar comentarios y hay un límite de envíos para evitar el spam.</p>
        </article>
    </div>

    <div class="pagina-botones">
        <a href="{{ route('noticias.index') }}" class="boton">Leer noticias</a>
        <a href="{{ route('contact') }}" class="boton boton-secundario">Escríbenos</a>
    </div>
@endsection
