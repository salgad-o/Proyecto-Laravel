{{-- Sección de comentarios de una noticia. Sin estilos ni scripts en línea (CSP). --}}
<section id="comentarios" class="comentarios">
    <h2 class="seccion-titulo comentarios-titulo">Comentarios ({{ $noticia->comentarios->count() }})</h2>

    @if (session('status') && ! session('aviso_noticia'))
        <p class="status-ok">{{ session('status') }}</p>
    @endif

    @auth
        @can('create', [\App\Models\Comentario::class, $noticia])
            <form method="POST" action="{{ route('comentarios.store', $noticia) }}" class="comentario-form">
                @csrf
                <label for="body">Escribe un comentario</label>
                <textarea id="body" name="body" rows="4" maxlength="1000" data-contador="contador-comentario" required>{{ old('body') }}</textarea>
                @error('body') <p class="error">{{ $message }}</p> @enderror
                <div class="comentario-form-pie">
                    {{-- Oculto hasta que app.js lo active: sin JavaScript no se actualizaría. --}}
                    <span id="contador-comentario" class="comentario-contador" aria-live="polite" hidden>{{ mb_strlen(old('body', '')) }}/1000</span>
                    <button type="submit" class="boton">Comentar</button>
                </div>
            </form>
        @endcan
    @else
        <div class="comentarios-acceso">
            <p>Inicia sesión para comentar</p>
            <a href="{{ route('login') }}" class="boton boton-secundario boton-sm">Iniciar sesión</a>
        </div>
    @endauth

    <div class="comentarios-lista">
        @forelse ($noticia->comentarios as $comentario)
            <article class="comentario">
                <div class="comentario-meta">
                    <span class="autor-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($comentario->user->name, 0, 1)) }}</span>
                    <span class="autor-nombre">{{ $comentario->user->name }}</span>
                    <time class="noticia-fecha" datetime="{{ $comentario->created_at->toIso8601String() }}" title="{{ $comentario->created_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm') }}">{{ $comentario->created_at->locale('es')->diffForHumans() }}</time>
                    @if ($comentario->edited_at)
                        <em class="noticia-fecha">(editado)</em>
                    @endif
                </div>

                <p class="texto-preformateado comentario-texto">{{ $comentario->body }}</p>

                @canany(['update', 'delete'], $comentario)
                    <div class="tarjeta-acciones">
                        @can('update', $comentario)
                            <a href="{{ route('comentarios.edit', $comentario) }}" class="boton boton-secundario boton-sm">Editar</a>
                        @endcan

                        @can('delete', $comentario)
                            <form method="POST" action="{{ route('comentarios.destroy', $comentario) }}" class="form-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="boton boton-peligro boton-sm" data-confirm="¿Seguro que quieres eliminar este comentario?">Eliminar</button>
                            </form>
                        @endcan
                    </div>
                @endcanany
            </article>
        @empty
            <div class="estado-vacio">
                <p class="estado-vacio-texto">Todavía no hay comentarios. ¡Sé el primero!</p>
            </div>
        @endforelse
    </div>
</section>
