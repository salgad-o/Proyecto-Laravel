<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Portal Seguro')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header>
        <nav>
            <div class="nav-left">
                <a href="{{ route('home') }}">Inicio</a>
                <a href="{{ route('noticias.index') }}">Noticias</a>
                <a href="{{ route('about') }}">Acerca</a>
                <a href="{{ route('contact') }}">Contacto</a>
            </div>

            <div class="nav-right">
                @auth
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <form method="POST" action="{{ route('logout') }}" style="display:inline">
                        @csrf
                        <button type="submit">Cerrar sesión</button>
                    </form>
                @else
                    <a href="{{ route('login') }}">Iniciar sesión</a>
                    <a href="{{ route('register') }}">Registrarse</a>
                @endauth
            </div>
        </nav>
    </header>

    <main>
        @yield('content')
    </main>
</body>
</html>