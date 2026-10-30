{{-- Sección de comentarios de una noticia. Sin estilos ni scripts en línea (CSP). --}}
<section id="comentarios" class="comentarios">
    <h2>Comentarios ({{ $noticia->comentarios->count() }})</h2>

    @if (session('status'))
        <p class="status-ok">{{ session('status') }}</p>
    @endif

    @forelse ($noticia->comentarios as $comentario)
        <article class="comentario">
            <p class="comentario-meta">
                <strong>{{ $comentario->user->name }}</strong>
                · {{ $comentario->created_at->format('d/m/Y H:i') }}
                @if ($comentario->edited_at)
                    · <em>(editado)</em>
                @endif
            </p>

            <p class="texto-preformateado">{{ $comentario->body }}</p>

            @can('update', $comentario)
                <a href="{{ route('comentarios.edit', $comentario) }}">Editar</a>
            @endcan

            @can('delete', $comentario)
                <form method="POST" action="{{ route('comentarios.destroy', $comentario) }}" class="form-inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" data-confirm="¿Seguro que quieres eliminar este comentario?">Eliminar</button>
                </form>
            @endcan
        </article>
    @empty
        <p>Todavía no hay comentarios. ¡Sé el primero!</p>
    @endforelse

    @auth
        @can('create', [\App\Models\Comentario::class, $noticia])
            <form method="POST" action="{{ route('comentarios.store', $noticia) }}" class="comentario-form">
                @csrf
                <label for="body">Escribe un comentario</label>
                <textarea id="body" name="body" rows="4" maxlength="1000" required>{{ old('body') }}</textarea>
                @error('body') <p class="error">{{ $message }}</p> @enderror
                <button type="submit">Comentar</button>
            </form>
        @endcan
    @else
        <p><a href="{{ route('login') }}">Inicia sesión</a> para comentar.</p>
    @endauth
</section>
