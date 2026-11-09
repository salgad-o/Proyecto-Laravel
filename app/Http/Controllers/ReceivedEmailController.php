<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexCorreosRecibidosRequest;
use App\Http\Requests\MarcarCorreoRecibidoRequest;
use App\Http\Requests\MostrarCorreoRecibidoRequest;
use App\Models\AuditLog;
use App\Models\ContactConsent;
use App\Models\ReceivedEmail;
use Illuminate\Support\Facades\Gate;

/**
 * Bandeja "Correos recibidos" del panel (NP-46 paso 7). Solo administradores.
 *
 * Todo el contenido de un correo es ENTRADA NO CONFIABLE (lo escribió cualquier
 * remitente): las vistas lo imprimen siempre con {{ }} y el cuerpo nunca se
 * interpreta como HTML ni se convierte en enlaces.
 */
class ReceivedEmailController extends Controller
{
    /**
     * Columnas del listado: el cuerpo (hasta 100 000 caracteres) NO se carga aquí.
     */
    private const COLUMNAS_LISTADO = ['id', 'from_email', 'from_name', 'subject', 'received_at', 'has_attachments', 'is_read'];

    public function index(IndexCorreosRecibidosRequest $request)
    {
        Gate::authorize('viewAny', ReceivedEmail::class);

        $estado = $request->validated('estado') ?? 'todos';

        $correos = ReceivedEmail::query()
            ->select(self::COLUMNAS_LISTADO)
            ->when($estado === 'no-leidos', fn ($consulta) => $consulta->where('is_read', false))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        if ($correos->currentPage() > $correos->lastPage()) {
            abort(404);
        }

        return view('correos.index', [
            'correos' => $correos,
            'estado' => $estado,
            'contadores' => $this->contadores(),
        ]);
    }

    public function show(MostrarCorreoRecibidoRequest $request, ReceivedEmail $correo)
    {
        Gate::authorize('view', $correo);

        // Ver un correo NO lo marca como leído: un GET no cambia datos. Se marca con
        // el botón (PATCH con CSRF).

        // Una sola consulta extra: la constancia de autorización si el correo es el
        // que envió el formulario de contacto (mismo Message-ID, paso 3).
        $constancia = ContactConsent::query()
            ->where('message_id', $correo->message_id)
            ->first(['privacy_accepted_at', 'legal_version']);

        return view('correos.show', [
            'correo' => $correo,
            'constancia' => $constancia,
            'volver' => $request->parametrosDeVuelta(),
        ]);
    }

    public function marcar(MarcarCorreoRecibidoRequest $request, ReceivedEmail $correo)
    {
        Gate::authorize('update', $correo);

        $leido = $request->leido();
        $destino = route('correos.show', ['correo' => $correo, ...$request->parametrosDeVuelta()]);

        // Mismo estado: nada que guardar ni que auditar.
        if ($correo->is_read === $leido) {
            return redirect()->to($destino)->with('status', $leido ? 'El correo ya estaba marcado como leído.' : 'El correo ya estaba marcado como no leído.');
        }

        // Asignación explícita (el modelo no admite asignación masiva).
        $antes = ['is_read' => $correo->is_read];
        $correo->is_read = $leido;
        $correo->read_at = $leido ? now() : null;
        $correo->save();

        // Solo is_read: ni asunto, ni cuerpo, ni remitente en la auditoría.
        AuditLog::registrar('marcar_correo', $correo, $antes, ['is_read' => $leido]);

        return redirect()->to($destino)->with('status', $leido ? 'Correo marcado como leído.' : 'Correo marcado como no leído.');
    }

    /**
     * Contadores de las pestañas en UNA consulta.
     *
     * @return array{todos: int, no-leidos: int}
     */
    private function contadores(): array
    {
        $fila = ReceivedEmail::query()
            ->toBase()
            ->selectRaw('count(*) as todos')
            ->selectRaw('sum(case when is_read = 0 then 1 else 0 end) as no_leidos')
            ->first();

        return [
            'todos' => (int) ($fila->todos ?? 0),
            'no-leidos' => (int) ($fila->no_leidos ?? 0),
        ];
    }
}
