{{--
    Tarjeta de noticia. Necesita $noticia con user y comentarios_count cargados.
    Opcionales: 'variante' ('vertical' u 'horizontal') y 'nivel' (2, 3 o 4, para el título).
--}}
@php
    $horizontal = ($variante ?? 'vertical') === 'horizontal';
    $nivelTitulo = in_array($nivel ?? 3, [2, 3, 4], true) ? ($nivel ?? 3) : 3;
@endphp
<article @class(['tarjeta', 'tarjeta-horizontal' => $horizontal])>
    {{-- Enlace solo para el ratón: el enlace accesible es el del título. --}}
    <a href="{{ route('noticias.show', $noticia) }}" class="tarjeta-bloque" tabindex="-1" aria-hidden="true">
        {{ mb_strtoupper(mb_substr($noticia->title, 0, 1)) }}
    </a>

    <div class="tarjeta-cuerpo">
        <h{{ $nivelTitulo }} class="tarjeta-titulo">
            <a href="{{ route('noticias.show', $noticia) }}" class="tarjeta-enlace">{{ $noticia->title }}</a>
        </h{{ $nivelTitulo }}>

        <p class="tarjeta-resumen">{{ $noticia->excerpt ?: \Illuminate\Support\Str::limit($noticia->body, 140) }}</p>

        <div class="tarjeta-pie">
            <span class="autor-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($noticia->user->name, 0, 1)) }}</span>
            <div class="tarjeta-autor">
                <span class="autor-nombre">{{ $noticia->user->name }}</span>
                @if ($noticia->published_at)
                    <time class="noticia-fecha" datetime="{{ $noticia->published_at->toIso8601String() }}">{{ $noticia->published_at->locale('es')->diffForHumans() }}</time>
                @endif
            </div>
            <span class="chip">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                {{ $noticia->comentarios_count }}<span @class(['sr-only' => ! $horizontal])> {{ $noticia->comentarios_count === 1 ? 'comentario' : 'comentarios' }}</span>
            </span>
        </div>

        @if ($horizontal)
            @canany(['update', 'delete'], $noticia)
                <div class="tarjeta-acciones">
                    @can('update', $noticia)
                        <a href="{{ route('noticias.edit', $noticia) }}" class="boton boton-secundario boton-sm">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 20h9"></path>
                                <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"></path>
                            </svg>
                            Editar
                        </a>
                    @endcan

                    @can('delete', $noticia)
                        <form method="POST" action="{{ route('noticias.destroy', $noticia) }}" class="form-inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="boton boton-peligro boton-sm" data-confirm="¿Seguro que quieres eliminar esta noticia?">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3 6h18"></path>
                                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                </svg>
                                Eliminar
                            </button>
                        </form>
                    @endcan
                </div>
            @endcanany
        @endif
    </div>
</article>
