{{-- Paginación propia: la vista por defecto de Laravel depende de clases de Tailwind. --}}
@if ($paginator->hasPages())
    <nav aria-label="Paginación" class="paginacion">
        @if ($paginator->onFirstPage())
            <span class="paginacion-paso paginacion-deshabilitado" aria-disabled="true">← Anterior</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="paginacion-paso" rel="prev">← Anterior</a>
        @endif

        <div class="paginacion-numeros">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="paginacion-puntos" aria-hidden="true">…</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="paginacion-numero paginacion-actual" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="paginacion-numero" aria-label="Ir a la página {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="paginacion-paso" rel="next">Siguiente →</a>
        @else
            <span class="paginacion-paso paginacion-deshabilitado" aria-disabled="true">Siguiente →</span>
        @endif
    </nav>
@endif
