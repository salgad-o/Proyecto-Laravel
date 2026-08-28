<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Portal Seguro')</title>
</head>
<body>
    <header>
        <nav>
            <a href="{{ route('home') }}">Inicio</a>
            <a href="{{ route('about') }}">Acerca</a>
            <a href="{{ route('contact') }}">Contacto</a>
        </nav>
    </header>

    <main>
        @yield('content')
    </main>
</body>
</html>