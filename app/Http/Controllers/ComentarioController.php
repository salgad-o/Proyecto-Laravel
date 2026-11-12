<?php

namespace App\Http\Controllers;

use App\Http\Requests\ComentarioRequest;
use App\Http\Requests\IndexComentariosRequest;
use App\Models\Comentario;
use App\Models\Noticia;
use Illuminate\Support\Facades\Gate;

class ComentarioController extends Controller
{
    /**
     * Moderación: todos los comentarios, solo para administradores.
     */
    public function index(IndexComentariosRequest $request)
    {
        Gate::authorize('viewAny', Comentario::class);

        $comentarios = Comentario::with(['user', 'noticia'])
            ->latest()
            ->latest('id')
            ->paginate(20);

        // destroy() vuelve con back(): al borrar el último comentario de la última
        // página se llegaría a una página vacía. Aquí se lleva a la última válida
        // (la 1 si no queda ninguno) y se conserva el aviso "Comentario eliminado.".
        if ($comentarios->currentPage() > $comentarios->lastPage()) {
            $request->session()->keep(['status']);

            return redirect()->route('comentarios.index', $comentarios->lastPage() > 1 ? ['page' => $comentarios->lastPage()] : []);
        }

        return view('comentarios.index', compact('comentarios'));
    }

    public function store(ComentarioRequest $request, Noticia $noticia)
    {
        Gate::authorize('create', [Comentario::class, $noticia]);

        $comentario = $noticia->comentarios()->make($request->validated());
        $comentario->user()->associate($request->user());
        $comentario->save();

        return redirect()
            ->to(route('noticias.show', $noticia).'#comentarios')
            ->with('status', 'Comentario publicado.');
    }

    public function edit(Comentario $comentario)
    {
        Gate::authorize('update', $comentario);

        return view('comentarios.edit', compact('comentario'));
    }

    public function update(ComentarioRequest $request, Comentario $comentario)
    {
        Gate::authorize('update', $comentario);

        $comentario->fill($request->validated());

        if ($comentario->isDirty('body')) {
            $comentario->edited_at = now();
        }

        $comentario->save();

        return redirect()
            ->to(route('noticias.show', $comentario->noticia_id).'#comentarios')
            ->with('status', 'Comentario actualizado.');
    }

    public function destroy(Comentario $comentario)
    {
        Gate::authorize('delete', $comentario);

        $comentario->delete();

        return back()->with('status', 'Comentario eliminado.');
    }
}
