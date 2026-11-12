<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuscarNoticiasRequest;
use App\Http\Requests\StoreNoticiaRequest;
use App\Http\Requests\UpdateNoticiaRequest;
use App\Models\AuditLog;
use App\Models\Noticia;
use App\Support\ProcesadorPortada;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class NoticiaController extends Controller
{
    /** Carpeta de las portadas dentro del disco local (privado). */
    private const CARPETA_PORTADAS = 'portadas';

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

    public function store(StoreNoticiaRequest $request): RedirectResponse
    {
        Gate::authorize('create', Noticia::class);

        $datos = $request->safe()->only([
            'title',
            'excerpt',
            'body',
            'status',
        ]);

        $nombre = null;
        $disco = Storage::disk('local');

        // La imagen se procesa ANTES de tocar la base de datos: si GD falla, el
        // FormRequest/ProcesadorPortada devuelve un error y no se guarda nada.
        if ($request->hasFile('cover_image')) {
            $bytes = ProcesadorPortada::procesar($request->file('cover_image'));
            $nombre = Str::random(40).'.webp';

            if (! $disco->put(self::CARPETA_PORTADAS."/{$nombre}", $bytes)) {
                throw new RuntimeException('No se pudo guardar la portada de la noticia.');
            }
        }

        try {
            $noticia = DB::transaction(function () use ($request, $datos, $nombre) {
                // El autor sale de la relación, no de un user_id enviado en el formulario.
                $noticia = $request->user()->noticias()->make([
                    'title' => $datos['title'],
                    'excerpt' => $datos['excerpt'] ?? null,
                    'body' => $datos['body'],
                    'status' => $datos['status'],
                ]);

                $noticia->published_at = $datos['status'] === 'publicada' ? now() : null;

                if ($nombre !== null) {
                    $noticia->cover_image = $nombre;
                }

                $noticia->save();

                if ($nombre !== null) {
                    AuditLog::registrar(
                        'cambiar_portada',
                        $noticia,
                        ['portada' => 'sin portada'],
                        ['portada' => 'con portada'],
                    );
                }

                return $noticia;
            });
        } catch (Throwable $e) {
            // La base de datos no cambió: el archivo nuevo quedaría huérfano.
            if ($nombre !== null) {
                $disco->delete(self::CARPETA_PORTADAS."/{$nombre}");
            }

            throw $e;
        }

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

    public function update(UpdateNoticiaRequest $request, Noticia $noticia): RedirectResponse
    {
        Gate::authorize('update', $noticia);

        $datos = $request->safe()->only([
            'title',
            'excerpt',
            'body',
            'status',
        ]);

        $quitar = $request->boolean('quitar_portada');
        $nombreNuevo = null;
        $disco = Storage::disk('local');

        // La imagen se procesa ANTES de tocar la base de datos: si GD falla, la
        // portada anterior y la noticia se quedan como estaban.
        if ($request->hasFile('cover_image')) {
            $bytes = ProcesadorPortada::procesar($request->file('cover_image'));
            $nombreNuevo = Str::random(40).'.webp';

            if (! $disco->put(self::CARPETA_PORTADAS."/{$nombreNuevo}", $bytes)) {
                throw new RuntimeException('No se pudo guardar la portada de la noticia.');
            }
        }

        try {
            [$anterior, $cambioPortada] = DB::transaction(function () use ($noticia, $datos, $nombreNuevo, $quitar) {
                $noticia->fill([
                    'title' => $datos['title'],
                    'excerpt' => $datos['excerpt'] ?? null,
                    'body' => $datos['body'],
                    'status' => $datos['status'],
                ]);

                $anterior = $noticia->cover_image;
                $cambioPortada = false;

                if ($nombreNuevo !== null) {
                    // Una imagen nueva tiene prioridad sobre "quitar portada".
                    $noticia->cover_image = $nombreNuevo;
                    $cambioPortada = true;
                } elseif ($quitar && $anterior !== null) {
                    $noticia->cover_image = null;
                    $cambioPortada = true;
                }

                if ($noticia->status === 'publicada' && $noticia->published_at === null) {
                    $noticia->published_at = now();
                }

                if ($noticia->status === 'borrador') {
                    $noticia->published_at = null;
                }

                $noticia->save();

                if ($cambioPortada) {
                    AuditLog::registrar(
                        'cambiar_portada',
                        $noticia,
                        ['portada' => $anterior ? 'con portada' : 'sin portada'],
                        ['portada' => $noticia->cover_image ? 'con portada' : 'sin portada'],
                    );
                }

                return [$anterior, $cambioPortada];
            });
        } catch (Throwable $e) {
            // La base de datos no cambió: el archivo nuevo quedaría huérfano.
            if ($nombreNuevo !== null) {
                $disco->delete(self::CARPETA_PORTADAS."/{$nombreNuevo}");
            }

            throw $e;
        }

        // Después del commit: si se borrara antes y la transacción fallara, la
        // noticia quedaría apuntando a un archivo que ya no existe.
        if ($anterior !== null && $anterior !== $noticia->cover_image) {
            $disco->delete(self::CARPETA_PORTADAS."/{$anterior}");
        }

        return redirect()->route('noticias.show', $noticia)
            ->with('status', 'Noticia actualizada.')
            ->with('aviso_noticia', true);
    }

    public function destroy(Noticia $noticia): RedirectResponse
    {
        Gate::authorize('delete', $noticia);

        $portada = $noticia->cover_image;

        $noticia->delete();

        if ($portada !== null) {
            Storage::disk('local')->delete(self::CARPETA_PORTADAS."/{$portada}");
        }

        // NP-20 paso 4: eliminar solo se ofrece en el panel, así que se vuelve allí.
        return redirect()->route('dashboard.noticias.index')->with('status', 'Noticia eliminada.');
    }

    /**
     * Sirve una portada desde el disco local (privado).
     *
     * - La ruta solo acepta nombres de 40 letras/números + ".webp": no llegan "/",
     *   ".." ni otras extensiones.
     * - Una noticia publicada la ve cualquiera. Un borrador solo su autor y los
     *   administradores; para los demás es 404 (no 403) para no revelar que existe.
     */
    public function portada(string $archivo): BinaryFileResponse
    {
        $noticia = Noticia::where('cover_image', $archivo)->first();

        abort_if($noticia === null, 404);

        if ($noticia->status === 'borrador') {
            abort_unless(Gate::allows('view', $noticia), 404);
        }

        $disco = Storage::disk('local');
        $ruta = self::CARPETA_PORTADAS."/{$archivo}";

        abort_unless($disco->exists($ruta), 404);

        $cache = $noticia->status === 'publicada'
            ? 'public, max-age=31536000, immutable'
            : 'private, no-store';

        $respuesta = response()->file($disco->path($ruta), [
            'Content-Type' => 'image/webp',
            // El navegador no debe "adivinar" otro tipo (por ejemplo HTML) por el contenido.
            'X-Content-Type-Options' => 'nosniff',
            // Solo este sitio puede incrustar la imagen (compatible con la COEP de NP-45).
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Disposition' => 'inline',
            // El nombre cambia en cada subida: el contenido de una URL nunca cambia.
            'Cache-Control' => $cache,
        ]);

        // BinaryFileResponse marca la respuesta como pública por defecto y elimina
        // "private". Un borrador no puede acabar en una caché compartida.
        if ($noticia->status === 'borrador') {
            $respuesta->setPrivate();
        }

        return $respuesta;
    }
}
