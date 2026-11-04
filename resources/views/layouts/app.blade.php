<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · Nexus Plays</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a href="#contenido" class="saltar-contenido">Saltar al contenido</a>

    <div class="layout">
        <aside id="menu-lateral" class="layout-barra">
            <nav aria-label="Navegación principal" class="menu-nav">
                <a href="{{ route('home') }}" class="marca">
                    <span class="marca-nombre">NEXUSPLAY</span>
                    <span class="marca-subtitulo">Revista de videojuegos</span>
                </a>

                {{-- Búsqueda por GET: solo lee datos y no cambia nada en el servidor, por eso no lleva @csrf.
                     La validación de "q" ya la hace BuscarNoticiasRequest (NP-6). --}}
                <form role="search" method="GET" action="{{ route('noticias.index') }}" class="barra-buscar">
                    <label class="sr-only" for="barra-buscar">Buscar noticias</label>
                    <input id="barra-buscar" type="search" name="q" maxlength="100" placeholder="Buscar noticias" @if (is_string(request('q'))) value="{{ request('q') }}" @endif>
                    <button type="submit" class="barra-buscar-boton">
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
                        <a href="{{ route('home') }}" @class(['menu-enlace', 'menu-enlace-activo' => request()->routeIs('home')]) @if (request()->routeIs('home')) aria-current="page" @endif>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 11l9-8 9 8"></path>
                                <path d="M5 10v10h14V10"></path>
                                <path d="M10 20v-6h4v6"></path>
                            </svg>
                            Inicio
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('noticias.index') }}" @class(['menu-enlace', 'menu-enlace-activo' => request()->routeIs('noticias.*')]) @if (request()->routeIs('noticias.*')) aria-current="page" @endif>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                <path d="M7 9h10"></path>
                                <path d="M7 13h10"></path>
                                <path d="M7 17h6"></path>
                            </svg>
                            Noticias
                        </a>
                    </li>
                </ul>

                @auth
                    <p id="menu-cuenta" class="menu-grupo">MI CUENTA</p>
                    <ul class="menu-lista" aria-labelledby="menu-cuenta">
                        <li>
                            <a href="{{ route('dashboard') }}" @class(['menu-enlace', 'menu-enlace-activo' => request()->routeIs('dashboard')]) @if (request()->routeIs('dashboard')) aria-current="page" @endif>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                                    <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                                    <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                                    <rect x="14" y="14" width="7" height="7" rx="1"></rect>
                                </svg>
                                Dashboard
                            </a>
                        </li>
                        {{-- NP-36: aquí irá Mi perfil --}}
                    </ul>
                @endauth

                {{-- NP-20: aquí irá el grupo "GESTIÓN", protegido con @can. --}}

                {{-- Bloque "Más": va pegado al fondo de la barra. <details> abre y cierra sin JS
                     y es accesible con teclado. Se renderiza abierto si la página actual está dentro. --}}
                <div class="menu-final">
                    <details class="menu-mas" @if (request()->routeIs('about', 'contact*', 'legal.*')) open @endif>
                        <summary class="menu-enlace menu-mas-titulo">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="5" cy="12" r="1"></circle>
                                <circle cx="12" cy="12" r="1"></circle>
                                <circle cx="19" cy="12" r="1"></circle>
                            </svg>
                            Más
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
                    <button type="button" id="menu-boton" class="menu-boton" aria-controls="menu-lateral" aria-expanded="false">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M4 7h16"></path>
                            <path d="M4 12h16"></path>
                            <path d="M4 17h16"></path>
                        </svg>
                        <span class="sr-only">Abrir menú</span>
                    </button>
                    <p class="header-titulo">@yield('title')</p>
                </div>

                <div class="header-sesion">
                    @auth
                        <span class="sesion-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}</span>
                        <span class="sesion-nombre">{{ Auth::user()->name }}</span>
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
    </div>
</body>
</html>
