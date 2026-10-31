<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNoticiaRequest;
use App\Http\Requests\UpdateNoticiaRequest;
use App\Models\Noticia;
use Illuminate\Support\Facades\Gate;

class NoticiaController extends Controller
{
    public function index()
    {
        $noticias = Noticia::publicadas()
            ->with('user')
            ->withCount('comentarios')
            ->latest('published_at')
            ->latest('id')
            ->paginate(9);

        if ($noticias->currentPage() > $noticias->lastPage()) {
            abort(404);
        }

        return view('noticias.index', compact('noticias'));
    }

    public function create()
    {
        Gate::authorize('create', Noticia::class);

        return view('noticias.create');
    }

    public function store(StoreNoticiaRequest $request)
    {
        Gate::authorize('create', Noticia::class);

        $noticia = $request->user()->noticias()->make($request->validated());
        $noticia->published_at = $noticia->status === 'publicada' ? now() : null;
        $noticia->save();

        // aviso_noticia: noticias.show también lista comentarios, que usan 'status' en su propia sección.
        return redirect()->route('noticias.show', $noticia)
            ->with('status', 'Noticia creada.')
            ->with('aviso_noticia', true);
    }

    public function show(Noticia $noticia)
    {
        Gate::authorize('view', $noticia);

        $noticia->load(['user', 'comentarios' => fn ($consulta) => $consulta->with('user')->oldest()->oldest('id')]);

        return view('noticias.show', compact('noticia'));
    }

    public function edit(Noticia $noticia)
    {
        Gate::authorize('update', $noticia);

        return view('noticias.edit', compact('noticia'));
    }

    public function update(UpdateNoticiaRequest $request, Noticia $noticia)
    {
        Gate::authorize('update', $noticia);

        $noticia->fill($request->validated());

        if ($noticia->status === 'publicada' && $noticia->published_at === null) {
            $noticia->published_at = now();
        }

        if ($noticia->status === 'borrador') {
            $noticia->published_at = null;
        }

        $noticia->save();

        return redirect()->route('noticias.show', $noticia)
            ->with('status', 'Noticia actualizada.')
            ->with('aviso_noticia', true);
    }

    public function destroy(Noticia $noticia)
    {
        Gate::authorize('delete', $noticia);

        $noticia->delete();

        return redirect()->route('noticias.index')->with('status', 'Noticia eliminada.');
    }
}