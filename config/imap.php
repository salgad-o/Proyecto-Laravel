<?php

use Webklex\IMAP\Events\FlagDeletedEvent;
use Webklex\IMAP\Events\FlagNewEvent;
use Webklex\IMAP\Events\FolderDeletedEvent;
use Webklex\IMAP\Events\FolderMovedEvent;
use Webklex\IMAP\Events\FolderNewEvent;
use Webklex\IMAP\Events\MessageCopiedEvent;
use Webklex\IMAP\Events\MessageDeletedEvent;
use Webklex\IMAP\Events\MessageMovedEvent;
use Webklex\IMAP\Events\MessageNewEvent;
use Webklex\IMAP\Events\MessageRestoredEvent;
use Webklex\PHPIMAP\Decoder\AttachmentDecoder;
use Webklex\PHPIMAP\Decoder\HeaderDecoder;
use Webklex\PHPIMAP\Decoder\MessageDecoder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Support\Masks\AttachmentMask;
use Webklex\PHPIMAP\Support\Masks\MessageMask;

/*
|--------------------------------------------------------------------------
| Recepción de correo por IMAP (NP-46 paso 5)
|--------------------------------------------------------------------------
|
| Publicado desde webklex/laravel-imap 6.2 y ajustado para este proyecto.
| El paquete fusiona este archivo con su config por defecto: lo que no se
| define aquí toma el valor del paquete.
|
| Lo usa solo App\Services\Correo\LectorBuzonWebklex, desde la consola
| (php artisan mail:receive). No hay ninguna ruta web que lo ejecute.
|
*/

return [

    // Cuenta por defecto. Fija: no se elige desde .env para no apuntar por error
    // a otra cuenta sin revisar su configuración.
    'default' => 'default',

    // Formato de fecha que el paquete usa en las búsquedas IMAP (SINCE).
    'date_format' => 'd-M-Y',

    'accounts' => [

        'default' => [
            'host' => env('IMAP_HOST'),

            // 993 = IMAP sobre TLS implícito (Gmail).
            'port' => (int) env('IMAP_PORT', 993),

            'protocol' => env('IMAP_PROTOCOL', 'imap'),

            // 'ssl' = TLS desde el primer byte. Nunca false/'notls': el usuario y la
            // contraseña viajarían en claro por la red.
            'encryption' => env('IMAP_ENCRYPTION', 'ssl'),

            // Verificar el certificado del servidor evita un ataque de intermediario
            // (alguien que se haga pasar por imap.gmail.com y capture la contraseña).
            // Cast a bool real: "false", "0", "no" y "off" se interpretan como false;
            // cualquier otro valor (o ninguno) deja la verificación ACTIVADA.
            'validate_cert' => filter_var(env('IMAP_VALIDATE_CERT', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,

            // Credenciales SOLO desde .env (contraseña de aplicación de Gmail). Sin valor
            // por defecto: si faltan, la conexión falla en vez de usar uno inventado.
            'username' => env('IMAP_USERNAME'),
            'password' => env('IMAP_PASSWORD'),

            // null = usuario y contraseña (LOGIN). 'oauth' necesitaría un token.
            'authentication' => null,

            // Sin proxy.
            'proxy' => [
                'socket' => null,
                'request_fulluri' => false,
                'username' => null,
                'password' => null,
            ],

            // Segundos: un servidor que no responde no deja el comando colgado.
            'timeout' => 30,

            'extensions' => [],
        ],

    ],

    'options' => [
        'delimiter' => '/',

        // D7: leer SIN marcar como leído. FT_PEEK hace que la consulta use
        // leaveUnread(): el paquete lee los flags antes del cuerpo y, si el mensaje
        // no estaba leído, le quita \Seen después de descargarlo. LectorBuzonWebklex
        // además llama a leaveUnread() de forma explícita.
        'fetch' => IMAP::FT_PEEK,

        // Pedir mensajes por UID (estable) y no por número de secuencia.
        'sequence' => IMAP::ST_UID,

        // Hace falta el cuerpo para el texto del correo.
        'fetch_body' => true,

        // Hace falta leer los flags para que FT_PEEK pueda restaurar "no leído".
        'fetch_flags' => true,

        // true: un mensaje malformado (por accidente o a propósito) se salta en vez
        // de abortar la lectura de todo el buzón.
        'soft_fail' => true,

        'rfc822' => true,

        // SIEMPRE false y fijo (no se lee de .env): en true el paquete imprime todo
        // el protocolo IMAP, incluido el comando LOGIN con la contraseña (D9).
        'debug' => false,

        'unescaped_search_dates' => false,
        'uid_cache' => true,
        'boundary' => '/boundary=(.*?(?=;)|(.*))/i',

        // Clave interna de la colección: número de lista. No se usa el Message-ID
        // como clave porque lo controla el remitente (podría repetirse).
        'message_key' => 'list',

        // Más recientes primero (el comando muestra los últimos N).
        'fetch_order' => 'desc',

        // Partes que se consideran adjuntos. Los adjuntos se descargan en memoria
        // como parte del cuerpo, pero el proyecto NUNCA los guarda en disco: no se
        // llama a Attachment::save() en ningún sitio. Solo se informa si hay o no.
        'dispositions' => ['attachment', 'inline'],

        'common_folders' => [
            'root' => 'INBOX',
            'junk' => 'INBOX/Junk',
            'draft' => 'INBOX/Drafts',
            'sent' => 'INBOX/Sent',
            'trash' => 'INBOX/Trash',
        ],

        'open' => [],
    ],

    // Detecta remitentes contradictorios (From, Reply-To, Return-Path, Sender...) y
    // marca el mensaje como "spoofed". Sin excepción: un correo sospechoso no debe
    // impedir leer el resto del buzón.
    'security' => [
        'detect_spoofing' => true,
        'detect_spoofing_exception' => false,
    ],

    'decoding' => [
        'options' => [
            // 'iconv' y no 'utf-8' (el valor del paquete): sin la extensión imap de PHP
            // (no está en XAMPP y desaparece en PHP 8.4), 'utf-8' deja los asuntos y
            // nombres codificados en MIME sin decodificar ("=?UTF-8?Q?Caf=C3=A9?=").
            // iconv sí los decodifica. El cuerpo se decodifica bien con 'utf-8'.
            'header' => 'iconv',
            'message' => 'utf-8',
            'attachment' => 'utf-8',
        ],
        'decoder' => [
            'header' => HeaderDecoder::class,
            'message' => MessageDecoder::class,
            'attachment' => AttachmentDecoder::class,
        ],
    ],

    'flags' => ['recent', 'flagged', 'answered', 'deleted', 'seen', 'draft'],

    'events' => [
        'message' => [
            'new' => MessageNewEvent::class,
            'moved' => MessageMovedEvent::class,
            'copied' => MessageCopiedEvent::class,
            'deleted' => MessageDeletedEvent::class,
            'restored' => MessageRestoredEvent::class,
        ],
        'folder' => [
            'new' => FolderNewEvent::class,
            'moved' => FolderMovedEvent::class,
            'deleted' => FolderDeletedEvent::class,
        ],
        'flag' => [
            'new' => FlagNewEvent::class,
            'deleted' => FlagDeletedEvent::class,
        ],
    ],

    'masks' => [
        'message' => MessageMask::class,
        'attachment' => AttachmentMask::class,
    ],
];
