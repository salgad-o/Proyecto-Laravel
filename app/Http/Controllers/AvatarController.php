<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAvatarRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ProcesadorAvatar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * NP-39: foto de perfil.
 *
 * - Subir y quitar: solo la foto PROPIA. Todo sale de $request->user(); no hay id
 *   de usuario en ninguna URL (evita IDOR).
 * - Las fotos se guardan en el disco "local" (storage/app/private), que NO es
 *   público: solo se sirven con show(), y solo si pertenecen a un usuario.
 * - Se guarda un WebP generado por ProcesadorAvatar, nunca el archivo subido.
 */
class AvatarController extends Controller
{
    /** Carpeta de las fotos dentro del disco local. */
    private const CARPETA = 'avatares';

    public function update(UpdateAvatarRequest $request): RedirectResponse
    {
        $usuario = $request->user();

        // Si la imagen no se puede procesar, lanza un error de validación en la bolsa
        // 'avatar' y no se toca ni el disco ni la base de datos.
        $bytes = ProcesadorAvatar::procesar($request->file('avatar'));

        // Nombre aleatorio: no revela el nombre original ni el id del usuario, y cambia
        // en cada subida (por eso show() puede pedir caché de un año).
        $nombre = Str::random(40).'.webp';
        $disco = Storage::disk('local');

        if (! $disco->put(self::CARPETA."/{$nombre}", $bytes)) {
            throw new RuntimeException('No se pudo guardar la foto de perfil.');
        }

        try {
            $anterior = DB::transaction(function () use ($usuario, $nombre) {
                $anterior = $usuario->avatar_path;

                // Asignación explícita: avatar_path no está en $fillable. avatar_path no
                // es auditable, así que Auditable no crea una fila 'editar' con el nombre.
                $usuario->avatar_path = $nombre;
                $usuario->save();

                AuditLog::registrar(
                    'cambiar_avatar',
                    $usuario,
                    ['foto' => $anterior ? 'con foto' : 'sin foto'],
                    ['foto' => 'con foto'],
                );

                return $anterior;
            });
        } catch (Throwable $e) {
            // La base de datos no cambió: el archivo nuevo quedaría huérfano.
            $disco->delete(self::CARPETA."/{$nombre}");

            throw $e;
        }

        // Después del commit: si se borrara antes y la transacción fallara, el
        // usuario quedaría apuntando a un archivo que ya no existe.
        if ($anterior) {
            $disco->delete(self::CARPETA."/{$anterior}");
        }

        return redirect()
            ->route('perfil.edit')
            ->with('status', 'Foto de perfil actualizada.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $anterior = $usuario->avatar_path;

        if (! $anterior) {
            // Nada cambió: no se audita.
            return redirect()
                ->route('perfil.edit')
                ->with('status', 'No tenías foto de perfil.');
        }

        DB::transaction(function () use ($usuario) {
            $usuario->avatar_path = null;
            $usuario->save();

            AuditLog::registrar('cambiar_avatar', $usuario, ['foto' => 'con foto'], ['foto' => 'sin foto']);
        });

        Storage::disk('local')->delete(self::CARPETA."/{$anterior}");

        return redirect()
            ->route('perfil.edit')
            ->with('status', 'Quitamos tu foto de perfil.');
    }

    /**
     * Pública: los visitantes ven las fotos de autores y comentaristas.
     *
     * La ruta solo acepta nombres de 40 letras/números + ".webp" (sin "/", ".." ni
     * otras extensiones). Además, la foto debe ser la VIGENTE de algún usuario: una
     * foto reemplazada que aún no se hubiera borrado del disco da 404.
     */
    public function show(string $archivo): BinaryFileResponse
    {
        $disco = Storage::disk('local');
        $ruta = self::CARPETA."/{$archivo}";

        abort_unless(User::where('avatar_path', $archivo)->exists() && $disco->exists($ruta), 404);

        return response()->file($disco->path($ruta), [
            'Content-Type' => 'image/webp',
            // El navegador no debe "adivinar" otro tipo (por ejemplo HTML) por el contenido.
            'X-Content-Type-Options' => 'nosniff',
            // Solo este sitio puede incrustar la imagen (compatible con la COEP de NP-45).
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Disposition' => 'inline',
            // El nombre cambia en cada subida: el contenido de una URL nunca cambia.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
