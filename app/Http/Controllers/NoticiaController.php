<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuscarNoticiasRequest;
use App\Http\Requests\StoreNoticiaRequest;
use App\Http\Requests\UpdateNoticiaRequest;
use App\Models\Noticia;
use Illuminate\Support\Facades\Gate;

class NoticiaController extends Controller
{
    public function index(BuscarNoticiasRequest $request)
    {
        $q = $request->validated('q');

        $noticias = Noticia::publicadas()
            ->buscar($q)
            ->with('user')
            ->withCount('comentarios')
            ->latest('published_at')
            ->latest('id')
            ->paginate(9)
            ->withQueryString();

        if ($noticias->currentPage() > $noticias->lastPage()) {
            abort(404);
        }

        return view('noticias.index', compact('noticias', 'q'));
    }

    public function create()
    {
        Gate::authorize('create', Noticia::class);

        return view('noticias.create');
    }

    public function store(StoreNoticiaRequest $request)
    {
        Gate::authorize('create', Noticia::class);

        $noticia = new Noticia;
        $noticia->user()->associate(auth()->user());
        $noticia->fill($request->validated());

        // Publicar al instante si el status es 'publicada'
        if ($request->filled('status') && $request->status === 'publicada') {
            $noticia->published_at = now();
        }

        // Guardar la imagen de portada (si viene validada y subida)
        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')
                ->storeAs(
                    'news/cover_images',              // carpeta dentro de storage/app/public
                    $noticia->id.'.'.$request->file('cover_image')->getClientOriginalExtension()
                );

            $noticia->cover_image = $path; // guardamos la ruta relativa
        }

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

        $masNoticias = Noticia::publicadas()
            ->whereKeyNot($noticia->getKey())
            ->latest('published_at')
            ->latest('id')
            ->take(3)
            ->get();

        return view('noticias.show', compact('noticia', 'masNoticias'));
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

        // Guardar la imagen de portada si se subió una nueva (solo en store, no en update simple)
        if ($request->hasFile('cover_image')) {
            // Eliminar la imagen anterior si existía
            if ($noticia->cover_image && Storage::disk('public')->exists($noticia->cover_image)) {
                Storage::disk('public')->delete($noticia->cover_image);
            }

            $path = $request->file('cover_image')
                ->storeAs(
                    'news/cover_images',
                    $noticia->id.'.'.$request->file('cover_image')->getClientOriginalExtension()
                );

            $noticia->cover_image = $path;
        }

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

        // NP-20 paso 4: eliminar solo se ofrece en el panel, así que se vuelve allí.
        return redirect()->route('dashboard.noticias.index')->with('status', 'Noticia eliminada.');
    }
}
