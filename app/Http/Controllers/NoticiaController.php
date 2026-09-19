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
        $noticias = Noticia::with('user')
            ->where('status', 'publicada')
            ->latest('published_at')
            ->paginate(9);

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

        return redirect()->route('noticias.show', $noticia);
    }

    public function show(Noticia $noticia)
    {
        Gate::authorize('view', $noticia);

        $noticia->load('user');

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

        return redirect()->route('noticias.show', $noticia);
    }

    public function destroy(Noticia $noticia)
    {
        Gate::authorize('delete', $noticia);

        $noticia->delete();

        return redirect()->route('noticias.index');
    }
}