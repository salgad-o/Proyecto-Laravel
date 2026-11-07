<!doctype html>
<html lang="es" @if ($consentimientoCookies) data-consentimiento="{{ $consentimientoCookies }}" @endif>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <title>@yield('title') · Nexus Plays</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#contenido" class="saltar-contenido">Saltar al contenido</a>

    <div class="layout">
        <aside id="menu-lateral" class="layout-barra">
            {{-- NP-36: pestaña para contraer la barra a solo iconos (solo escritorio y con JS).
                 app.js mantiene aria-expanded y el texto; el CSS gira el chevron. --}}
            <button type="button" id="barra-plegar" class="barra-pestana" aria-controls="menu-lateral" aria-expanded="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 6l-6 6 6 6"></path>
                </svg>
                <span class="sr-only">Contraer menú</span>
            </button>

            <nav aria-label="Navegación principal" class="menu-nav">
                <a href="{{ route('home') }}" class="marca">
                    <img class="marca-logo" src="{{ asset('images/nexusplays-logo-dark.png') }}" alt="Nexus Plays" width="691" height="77">
                    <span class="marca-subtitulo">Revista de videojuegos</span>
                    <img class="marca-monograma" src="{{ asset('favicon.svg') }}" alt="" width="40" height="40" aria-hidden="true">
                </a>

                {{-- Búsqueda por GET: solo lee datos y no cambia nada en el servidor, por eso no lleva @csrf.
                     La validación de "q" ya la hace BuscarNoticiasRequest (NP-6). --}}
                <form role="search" method="GET" action="{{ route('noticias.index') }}" class="barra-buscar">
                    <label class="sr-only" for="barra-buscar">Buscar noticias</label>
                    <input id="barra-buscar" type="search" name="q" maxlength="100" placeholder="Buscar noticias" @if (is_string(request('q'))) value="{{ request('q') }}" @endif>
                    <button type="submit" class="barra-buscar-boton" data-etiqueta="Buscar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="M20 20l-3.5-3.5"></path>
                        </svg>
                        <span class="sr-only">Buscar</span>
                    </button>
                </form>

                <p id="menu-explorar" class="menu-grupo">EXPLORAR</p>
                <ul class="menu-lista" aria-labelledby="menu-explorar">
                    <li>
                        <a href="{{ route('home') }}" @class(['menu-enlace', 'menu-enlace-activo' => request()->routeIs('home')]) @if (request()->routeIs('home')) aria-current="page" @endif data-etiqueta="Inicio">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 11l9-8 9 8"></path>
                                <path d="M5 10v10h14V10"></path>
                                <path d="M10 20v-6h4v6"></path>
                            </svg>
                            <span class="menu-texto">Inicio</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('noticias.index') }}" @class(['menu-enlace', 'menu-enlace-activo' => request()->routeIs('noticias.*')]) @if (request()->routeIs('noticias.*')) aria-current="page" @endif data-etiqueta="Noticias">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                <path d="M7 9h10"></path>
                                <path d="M7 13h10"></path>
                                <path d="M7 17h6"></path>
                            </svg>
                            <span class="menu-texto">Noticias</span>
                        </a>
                    </li>
                </ul>

                @auth
                    <p id="menu-cuenta" class="menu-grupo">MI CUENTA</p>
                    <ul class="menu-lista" aria-labelledby="menu-cuenta">
                        <li>
                            <a href="{{ route('dashboard') }}" @class(['menu-enlace', 'menu-enlace-activo' => request()->routeIs('dashboard')]) @if (request()->routeIs('dashboard')) aria-current="page" @endif data-etiqueta="Dashboard">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                                    <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                                    <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                                    <rect x="14" y="14" width="7" height="7" rx="1"></rect>
                                </svg>
                                <span class="menu-texto">Dashboard</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('perfil.edit') }}" @class(['menu-enlace', 'menu-enlace-activo' => request()->routeIs('perfil.*')]) @if (request()->routeIs('perfil.*')) aria-current="page" @endif data-etiqueta="Mi perfil">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="12" cy="8" r="4"></circle>
                                    <path d="M4 21v-1a7 7 0 0 1 7-7h2a7 7 0 0 1 7 7v1"></path>
                                </svg>
                                <span class="menu-texto">Mi perfil</span>
                            </a>
                        </li>
                    </ul>
                @endauth

                {{-- NP-20: aquí irá el grupo "GESTIÓN", protegido con @can. --}}

                {{-- Bloque "Más": va pegado al fondo de la barra. <details> abre y cierra sin JS
                     y es accesible con teclado. Se renderiza abierto si la página actual está dentro. --}}
                <div class="menu-final">
                    <details class="menu-mas" @if (request()->routeIs('about', 'contact*', 'legal.*')) open @endif>
                        <summary class="menu-enlace menu-mas-titulo" data-etiqueta="Más">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="5" cy="12" r="1"></circle>
                                <circle cx="12" cy="12" r="1"></circle>
                                <circle cx="19" cy="12" r="1"></circle>
                            </svg>
                            <span class="menu-texto">Más</span>
                            <svg class="menu-mas-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M6 9l6 6 6-6"></path>
                            </svg>
                        </summary>
                        <ul class="menu-lista menu-mas-lista">
                            <li><a href="{{ route('about') }}" @class(['menu-enlace', 'menu-mas-enlace', 'menu-enlace-activo' => request()->routeIs('about')]) @if (request()->routeIs('about')) aria-current="page" @endif>Acerca</a></li>
                            <li><a href="{{ route('contact') }}" @class(['menu-enlace', 'menu-mas-enlace', 'menu-enlace-activo' => request()->routeIs('contact*')]) @if (request()->routeIs('contact*')) aria-current="page" @endif>Contacto</a></li>
                            <li><a href="{{ route('legal.cookies') }}" @class(['menu-enlace', 'menu-mas-enlace', 'menu-enlace-activo' => request()->routeIs('legal.cookies')]) @if (request()->routeIs('legal.cookies')) aria-current="page" @endif>Política de cookies</a></li>
                            <li><a href="{{ route('legal.privacidad') }}" @class(['menu-enlace', 'menu-mas-enlace', 'menu-enlace-activo' => request()->routeIs('legal.privacidad')]) @if (request()->routeIs('legal.privacidad')) aria-current="page" @endif>Política de privacidad</a></li>
                            <li><a href="{{ route('legal.terminos') }}" @class(['menu-enlace', 'menu-mas-enlace', 'menu-enlace-activo' => request()->routeIs('legal.terminos')]) @if (request()->routeIs('legal.terminos')) aria-current="page" @endif>Términos de uso</a></li>
                        </ul>
                    </details>
                    <p class="menu-copy">© {{ now()->year }} Nexus Plays</p>
                </div>
            </nav>
        </aside>

        <div id="menu-fondo" class="menu-fondo"></div>

        <div class="layout-columna">
            <header class="layout-header">
                <div class="header-izquierda">
                    {{-- Solo móvil (< 900 px): abre el panel deslizante. En escritorio se usa #barra-plegar. --}}
                    <button type="button" id="menu-boton" class="menu-boton" aria-controls="menu-lateral" aria-expanded="false">
                        {{-- Icono de "panel lateral": rectángulo con una línea vertical a la izquierda. --}}
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="4" width="18" height="16" rx="2.5"></rect>
                            <path d="M9 4v16"></path>
                        </svg>
                        <span class="sr-only">Abrir menú</span>
                    </button>
                    <p class="header-titulo">@yield('title')</p>
                </div>

                <div class="header-sesion">
                    @auth
                        {{-- NP-36: la foto (o la inicial) y el nombre son un solo enlace a "Mi perfil".
                             La foto es decorativa (alt="") y la inicial aria-hidden: el nombre
                             accesible del enlace es solo el nombre del usuario.
                             NP-39: sin lazy, el header está arriba y siempre visible. --}}
                        <a href="{{ route('perfil.edit') }}" class="sesion-perfil" @if (request()->routeIs('perfil.*')) aria-current="page" @endif>
                            <x-avatar :usuario="Auth::user()" :tamano="36" clase="sesion-avatar" />
                            <span class="sesion-nombre">{{ Auth::user()->name }}</span>
                        </a>
                        <a href="{{ route('dashboard') }}" class="sesion-enlace">
                            <svg class="sesion-icono" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                                <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                                <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                                <rect x="14" y="14" width="7" height="7" rx="1"></rect>
                            </svg>
                            <span class="sesion-texto">Dashboard</span>
                        </a>
                        <form method="POST" action="{{ route('logout') }}" class="sesion-form">
                            @csrf
                            <button type="submit" class="sesion-salir">
                                <svg class="sesion-icono" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                    <path d="M16 17l5-5-5-5"></path>
                                    <path d="M21 12H9"></path>
                                </svg>
                                <span class="sesion-texto">Cerrar sesión</span>
                            </button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="sesion-enlace">
                            <span class="solo-escritorio">Iniciar sesión</span>
                            <span class="solo-movil">Entrar</span>
                        </a>
                        <a href="{{ route('register') }}" class="sesion-registro">Registrarse</a>
                    @endauth
                </div>
            </header>

            {{-- Los mensajes de session('status') los muestra cada vista. --}}
            <main id="contenido" class="layout-main" tabindex="-1">
                @yield('content')
            </main>
        </div>

        {{-- Banner de cookies: solo si todavía no hay una elección válida. --}}
        @if ($consentimientoCookies === null)
            @include('partials.banner-cookies')
        @endif
    </div>
</body>
</html>
