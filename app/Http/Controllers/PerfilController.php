<?php

namespace App\Http\Controllers;

use App\Http\Requests\CerrarSesionesRequest;
use App\Http\Requests\UpdateBiografiaRequest;
use App\Http\Requests\UpdateContrasenaRequest;
use App\Http\Requests\UpdateNombreRequest;
use App\Models\AuditLog;
use App\Support\SesionesUsuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NP-36: "Mi perfil". Cada usuario ve y edita SOLO su propia cuenta
 * ($request->user()): no hay id en la URL que se pueda cambiar.
 *
 * Un formulario y una ruta por campo. El correo no se edita aquí: con él se
 * inicia sesión y se recupera la contraseña, así que el cambio se pide por Contacto.
 */
class PerfilController extends Controller
{
    public function edit(Request $request)
    {
        // Una sola consulta extra (el rol, con label y description).
        $usuario = $request->user()->load('role');

        return view('perfil.edit', [
            'usuario' => $usuario,
            'editaBio' => $usuario->hasRole('admin', 'editor'),
            'versionLegal' => $usuario->legal_version,
            'aceptoTerminosEl' => $usuario->terms_accepted_at,
        ]);
    }

    public function updateNombre(UpdateNombreRequest $request)
    {
        $usuario = $request->user();

        // Asignación explícita de un solo campo. Un solo save(): Auditable registra
        // 'editar' con antes/después, y si nada cambió no escribe nada.
        $usuario->name = $request->validated('name');
        $usuario->save();

        return redirect()
            ->route('perfil.edit')
            ->with('status', 'Nombre actualizado.');
    }

    public function updateBiografia(UpdateBiografiaRequest $request)
    {
        // authorize() del FormRequest ya respondió 403 a un lector.
        $usuario = $request->user();

        $usuario->bio = $request->validated('bio');
        $usuario->save();

        return redirect()
            ->route('perfil.edit')
            ->with('status', 'Biografía actualizada.');
    }

    /**
     * Pestaña Seguridad: cambio de contraseña (paso 3) y sesiones activas (paso 4).
     */
    public function seguridad(Request $request)
    {
        $sesiones = SesionesUsuario::listar($request->user(), $request->session()->getId());

        return view('perfil.seguridad', [
            'sesiones' => $sesiones,
            'sesionesDisponibles' => SesionesUsuario::disponible(),
            'hayOtrasSesiones' => $sesiones->contains(fn ($sesion) => ! $sesion->esActual),
        ]);
    }

    /**
     * Pestaña Actividad (NP-36 paso 5): mis noticias publicadas y mis comentarios.
     *
     * TODO sale del usuario autenticado: no se lee ningún user_id de la URL (evita IDOR).
     * Los contadores salen del total de cada paginador: no hacen falta consultas extra.
     */
    public function actividad(Request $request)
    {
        $usuario = $request->user();
        $publica = $usuario->hasRole('admin', 'editor');

        // Solo quienes publican tienen lista de noticias. Solo las publicadas:
        // los borradores llegarán al panel de administración (NP-20).
        $noticias = $publica
            ? $usuario->noticias()
                ->publicadas()
                ->withCount('comentarios')
                ->latest('published_at')
                ->latest('id')
                ->paginate(5, ['*'], 'noticias')
            : null;

        // De la noticia solo las columnas que usan la vista y NoticiaPolicy@view
        // (status y user_id): el cuerpo no hace falta.
        $comentarios = $usuario->comentarios()
            ->with('noticia:id,title,status,user_id')
            ->latest()
            ->latest('id')
            ->paginate(10, ['*'], 'comentarios');

        // Página fuera de rango → 404, como NoticiaController@index.
        // Con 0 elementos lastPage() es 1, así que la página 1 sigue siendo válida.
        foreach (array_filter([$noticias, $comentarios]) as $paginador) {
            if ($paginador->currentPage() > $paginador->lastPage()) {
                abort(404);
            }
        }

        // Paginar una lista no reinicia la otra. Se usa la página ya validada por el
        // paginador, no el texto crudo de la URL.
        if ($noticias) {
            if ($comentarios->currentPage() > 1) {
                $noticias->appends('comentarios', $comentarios->currentPage());
            }

            if ($noticias->currentPage() > 1) {
                $comentarios->appends('noticias', $noticias->currentPage());
            }
        }

        return view('perfil.actividad', [
            'publica' => $publica,
            'noticias' => $noticias,
            'comentarios' => $comentarios,
        ]);
    }

    /**
     * NP-36 paso 4: cierra la sesión en los demás dispositivos.
     * La contraseña actual (CerrarSesionesRequest) es la confirmación.
     */
    public function cerrarSesiones(CerrarSesionesRequest $request)
    {
        $usuario = $request->user();

        // Mismo criterio que el cambio de contraseña: todo o nada.
        $cerradas = DB::transaction(function () use ($request, $usuario) {
            // ID nuevo ANTES de cerrar las demás: así solo se conserva la sesión con el ID nuevo
            // (y una cookie de sesión copiada antes de este momento ya no sirve).
            $request->session()->regenerate(true);

            $cerradas = SesionesUsuario::cerrarOtras($usuario, $request->session()->getId());

            // Solo se audita la acción, sin ids de sesión ni otros datos.
            AuditLog::registrar('cerrar_sesiones', $usuario, null, null);

            return $cerradas;
        });

        $mensaje = match (true) {
            $cerradas === 0 => 'No había otras sesiones abiertas.',
            $cerradas === 1 => 'Cerramos tu sesión en 1 dispositivo.',
            default => "Cerramos tu sesión en {$cerradas} dispositivos.",
        };

        return redirect()
            ->route('perfil.seguridad')
            ->with('status', $mensaje);
    }

    public function updateContrasena(UpdateContrasenaRequest $request)
    {
        $usuario = $request->user();

        // Todo o nada: si algo falla, la contraseña NO cambia. Así nunca queda una
        // contraseña nueva sin auditar o con las otras sesiones todavía abiertas.
        DB::transaction(function () use ($request, $usuario) {
            // Texto plano: el cast 'hashed' del modelo la hashea una sola vez.
            // El remember_token nuevo invalida las cookies "recordarme" de otros equipos.
            $usuario->password = $request->validated('password');
            $usuario->setRememberToken(Str::random(60));
            // password y remember_token no son auditables: Auditable no crea fila 'editar'.
            $usuario->save();

            // Solo se audita la acción: nunca la contraseña ni su hash.
            AuditLog::registrar('cambiar_password', $usuario, null, null);

            // Un enlace de recuperación pendiente deja de servir.
            DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))
                ->where('email', $usuario->email)
                ->delete();

            // ID de sesión nuevo (y se destruye el anterior). Va ANTES de cerrar las
            // otras sesiones para conservar solo la sesión con el ID nuevo.
            $request->session()->regenerate(true);

            SesionesUsuario::cerrarOtras($usuario, $request->session()->getId());
        });

        return redirect()
            ->route('perfil.seguridad')
            ->with('status', 'Contraseña actualizada. Cerramos tu sesión en los demás dispositivos.');
    }
}
