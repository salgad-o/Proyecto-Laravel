<?php

namespace App\Services\Correo;

use App\Contracts\LectorBuzon;
use App\Support\Correo\CorreoEntrante;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Lee el buzón por IMAP con webklex/php-imap 6.2 (NP-46 paso 5).
 *
 * - Cuenta 'default' de config/imap.php, carpeta INBOX.
 * - No marca nada como leído (D7): leaveUnread() + fetch_flags (ver config/imap.php).
 * - Devuelve CorreoEntrante: nada del paquete sale de esta clase.
 * - Siempre desconecta, también si algo falla.
 */
class LectorBuzonWebklex implements LectorBuzon
{
    public function __construct(private readonly ClientManager $clientes) {}

    public function recientes(int $limite, int $dias): iterable
    {
        $cliente = $this->clientes->account('default');

        try {
            $cliente->connect();

            // Por ruta: evita listar todas las carpetas de la cuenta.
            $bandeja = $cliente->getFolderByPath('INBOX');
            if ($bandeja === null) {
                throw new RuntimeException('No se encontró la carpeta INBOX.');
            }

            $mensajes = $bandeja->query()
                // D7: leer sin marcar como leído. El paquete pide los FLAGS antes que
                // el cuerpo y, si el mensaje no estaba leído, le quita \Seen al terminar.
                ->leaveUnread()
                ->setFetchFlags(true)
                ->setFetchBody(true)
                ->whereSince(CarbonImmutable::now()->subDays($dias)->startOfDay())
                ->fetchOrderDesc()
                ->limit($limite)
                ->get();

            // Se convierte todo ANTES de desconectar (nada perezoso sale de aquí).
            $correos = [];
            foreach ($mensajes as $mensaje) {
                $correos[] = $this->convertir($mensaje);
            }

            return $correos;
        } finally {
            try {
                $cliente->disconnect();
            } catch (Throwable) {
                // Si la conexión ya está rota, no tapar el error original.
            }
        }
    }

    /**
     * Mensaje del paquete → CorreoEntrante. Público para probarlo con Message::fromString().
     */
    public function convertir(Message $mensaje): CorreoEntrante
    {
        $remitente = $this->primero($mensaje->get('from'));
        $remitenteCorreo = $remitente instanceof Address ? trim($remitente->mail) : '';
        $remitenteNombre = $remitente instanceof Address && trim($remitente->personal) !== '' ? trim($remitente->personal) : null;

        $asunto = $this->primero($mensaje->get('subject'));
        $asunto = is_string($asunto) && $asunto !== '' ? $asunto : null;

        $fecha = $this->primero($mensaje->get('date'));
        $recibidoEn = $fecha instanceof \DateTimeInterface ? CarbonImmutable::instance($fecha) : null;

        $messageId = $this->primero($mensaje->get('message_id'));
        $messageId = is_string($messageId) ? trim($messageId, " \t\r\n<>") : '';
        if ($messageId === '') {
            $messageId = self::messageIdSustituto($remitenteCorreo, $recibidoEn, $asunto);
        }

        return new CorreoEntrante(
            messageId: $messageId,
            remitenteCorreo: $remitenteCorreo,
            remitenteNombre: $remitenteNombre,
            asunto: $asunto,
            texto: CorreoEntrante::textoDe(
                $mensaje->hasTextBody() ? $mensaje->getTextBody() : null,
                $mensaje->hasHTMLBody() ? $mensaje->getHTMLBody() : null,
            ),
            recibidoEn: $recibidoEn,
            tieneAdjuntos: $mensaje->hasAttachments(),
        );
    }

    /**
     * Message-ID estable para un correo que no lo trae: mismo remitente, fecha y
     * asunto → mismo ID, así la deduplicación del paso 6 también funciona con él.
     */
    public static function messageIdSustituto(string $remitente, ?CarbonImmutable $fecha, ?string $asunto): string
    {
        $base = implode("\n", [
            mb_strtolower($remitente),
            $fecha?->utc()->toIso8601String() ?? '',
            $asunto ?? '',
        ]);

        return hash('sha256', $base).'@sin-message-id.local';
    }

    /**
     * Primer valor de un atributo del paquete (o null si no hay).
     */
    private function primero(mixed $atributo): mixed
    {
        if (is_object($atributo) && method_exists($atributo, 'first')) {
            $valor = $atributo->first();

            return $valor === false ? null : $valor;
        }

        return $atributo;
    }
}
