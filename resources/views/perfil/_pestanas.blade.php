{{-- Pestañas de "Mi perfil" (NP-36). Cada pestaña es una URL propia.
     Recibe $pestanas = [['nombre.de.ruta', 'Texto'], ...] y SOLO muestra las
     que tienen ruta registrada: así los pasos 3-6 agregan su pestaña con solo
     crear la ruta, y nunca hay un enlace a una página que no existe. --}}
@php
    $pestanasVisibles = array_values(array_filter($pestanas, fn (array $pestana) => Route::has($pestana[0])));
@endphp

@if ($pestanasVisibles !== [])
    <nav class="pestanas" aria-label="Secciones de mi perfil">
        <ul class="pestanas-lista">
            @foreach ($pestanasVisibles as [$ruta, $texto])
                <li>
                    <a href="{{ route($ruta) }}" @class(['pestana', 'pestana-activa' => request()->routeIs($ruta)]) @if (request()->routeIs($ruta)) aria-current="page" @endif>{{ $texto }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
