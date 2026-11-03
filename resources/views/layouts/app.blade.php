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
            <nav aria-label="Navegación principal">
                <a href="{{ route('home') }}" class="marca">
                    <span class="marca-nombre">NEXUSPLAY</span>
                    <span class="marca-subtitulo">Revista de videojuegos</span>
                </a>

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
                    <li>
                        <a href="{{ route('about') }}" @class(['menu-enlace', 'menu-enlace-activo' => request()->routeIs('about')]) @if (request()->routeIs('about')) aria-current="page" @endif>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="9"></circle>
                                <path d="M12 11v5"></path>
                                <path d="M12 8h.01"></path>
                            </svg>
                            Acerca
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('contact') }}" @class(['menu-enlace', 'menu-enlace-activo' => request()->routeIs('contact*')]) @if (request()->routeIs('contact*')) aria-current="page" @endif>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                <path d="M3 7l9 6 9-6"></path>
                            </svg>
                            Contacto
                        </a>
                    </li>
                </ul>

                {{-- NP-20: aquí irá el grupo "GESTIÓN", protegido con @can. --}}
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

            <footer class="layout-pie">
                <p>Nexus Plays · Revista de videojuegos</p>
                <nav aria-label="Enlaces del pie de página" class="pie-enlaces">
                    <a href="{{ route('home') }}">Inicio</a>
                    <a href="{{ route('noticias.index') }}">Noticias</a>
                    <a href="{{ route('about') }}">Acerca</a>
                    <a href="{{ route('contact') }}">Contacto</a>
                </nav>
            </footer>
        </div>
    </div>
</body>
</html>
