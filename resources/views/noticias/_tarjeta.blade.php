{{-- Tarjeta de noticia. Necesita $noticia con user y comentarios_count cargados. --}}
<article class="tarjeta">
    {{-- Enlace solo para el ratón: el enlace accesible es el del título. --}}
    <a href="{{ route('noticias.show', $noticia) }}" class="tarjeta-bloque" tabindex="-1" aria-hidden="true">
        {{ mb_strtoupper(mb_substr($noticia->title, 0, 1)) }}
    </a>

    <div class="tarjeta-cuerpo">
        <h3 class="tarjeta-titulo">
            <a href="{{ route('noticias.show', $noticia) }}" class="tarjeta-enlace">{{ $noticia->title }}</a>
        </h3>

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
                {{ $noticia->comentarios_count }}<span class="sr-only"> {{ $noticia->comentarios_count === 1 ? 'comentario' : 'comentarios' }}</span>
            </span>
        </div>
    </div>
</article>
