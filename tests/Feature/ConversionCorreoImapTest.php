<?php

namespace Tests\Feature;

use App\Services\Correo\LectorBuzonWebklex;
use App\Support\Correo\CorreoEntrante;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;

/**
 * NP-46 paso 5: conversión de un mensaje de webklex/php-imap a CorreoEntrante.
 * Los mensajes se construyen con Message::fromString() a partir de correos en
 * texto (.eml): no hay conexión IMAP. Se usa config/imap.php del proyecto.
 */
class ConversionCorreoImapTest extends TestCase
{
    private function convertir(string $cabeceras, string $cuerpo): CorreoEntrante
    {
        $eml = str_replace("\n", "\r\n", trim($cabeceras))."\r\n\r\n".str_replace("\n", "\r\n", $cuerpo);
        $mensaje = Message::fromString($eml, Config::make(config('imap')));

        return $this->app->make(LectorBuzonWebklex::class)->convertir($mensaje);
    }

    public function test_un_correo_de_texto_se_convierte_completo(): void
    {
        $correo = $this->convertir(<<<'EML'
From: Ana Lectora <ana@nexus.test>
To: buzon@nexus.test
Subject: Sugerencia
Date: Sat, 07 Nov 2026 10:00:00 +0000
Message-ID: <abc123@nexus.test>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
EML, "Hola, me gusta la revista.\n");

        $this->assertSame('abc123@nexus.test', $correo->messageId);
        $this->assertSame('ana@nexus.test', $correo->remitenteCorreo);
        $this->assertSame('Ana Lectora', $correo->remitenteNombre);
        $this->assertSame('Sugerencia', $correo->asunto);
        $this->assertSame('Hola, me gusta la revista.', trim($correo->texto));
        $this->assertSame('2026-11-07T10:00:00+00:00', $correo->recibidoEn->utc()->toIso8601String());
        $this->assertFalse($correo->tieneAdjuntos);
    }

    public function test_los_asuntos_y_nombres_en_mime_se_decodifican_sin_ext_imap(): void
    {
        $correo = $this->convertir(<<<'EML'
From: =?UTF-8?Q?Jos=C3=A9_Mu=C3=B1oz?= <jose@nexus.test>
Subject: =?UTF-8?Q?Caf=C3=A9_y_m=C3=A1s?= =?ISO-8859-1?Q?_se=F1or?=
Message-ID: <mime@nexus.test>
Content-Type: text/plain; charset=UTF-8
EML, "Hola\n");

        $this->assertSame('José Muñoz', $correo->remitenteNombre);
        $this->assertSame('Café y más señor', $correo->asunto);
    }

    public function test_un_correo_solo_html_se_convierte_a_texto_sin_renderizarlo(): void
    {
        $correo = $this->convertir(<<<'EML'
From: ana@nexus.test
Subject: HTML
Message-ID: <html@nexus.test>
MIME-Version: 1.0
Content-Type: text/html; charset=UTF-8
EML, "<script>alert(1)</script><p>Hola &amp; adi&oacute;s</p>\n");

        $this->assertSame('Hola & adiós', $correo->texto);
        $this->assertNull($correo->remitenteNombre);
    }

    public function test_con_texto_y_html_usa_la_parte_de_texto(): void
    {
        $correo = $this->convertir(<<<'EML'
From: ana@nexus.test
Subject: Alternativo
Message-ID: <alt@nexus.test>
MIME-Version: 1.0
Content-Type: multipart/alternative; boundary="LIMITE"
EML, <<<'CUERPO'
--LIMITE
Content-Type: text/plain; charset=UTF-8

Versión de texto
--LIMITE
Content-Type: text/html; charset=UTF-8

<p>Versión <b>HTML</b></p>
--LIMITE--

CUERPO);

        $this->assertSame('Versión de texto', trim($correo->texto));
    }

    public function test_un_adjunto_se_detecta_y_no_se_guarda_en_disco(): void
    {
        $antes = $this->archivosEnStorage();

        $correo = $this->convertir(<<<'EML'
From: ana@nexus.test
Subject: Con adjunto
Message-ID: <adjunto@nexus.test>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="LIMITE"
EML, <<<'CUERPO'
--LIMITE
Content-Type: text/plain; charset=UTF-8

Te envío un archivo.
--LIMITE
Content-Type: application/octet-stream; name="malware.exe"
Content-Disposition: attachment; filename="malware.exe"
Content-Transfer-Encoding: base64

TVqQAAMAAAAEAAAA//8AALgAAAAAAAAAQAAAAAAAAAAA
--LIMITE--

CUERPO);

        $this->assertTrue($correo->tieneAdjuntos);
        $this->assertSame('Te envío un archivo.', trim($correo->texto));
        $this->assertSame($antes, $this->archivosEnStorage());
    }

    public function test_sin_message_id_genera_uno_estable(): void
    {
        $cabeceras = <<<'EML'
From: ana@nexus.test
Subject: Sin ID
Date: Sat, 07 Nov 2026 10:00:00 +0000
Content-Type: text/plain; charset=UTF-8
EML;

        $primero = $this->convertir($cabeceras, "Hola\n");
        $segundo = $this->convertir($cabeceras, "Otro cuerpo distinto\n");

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}@sin-message-id\.local$/', $primero->messageId);
        // Estable: mismo remitente, fecha y asunto → mismo ID (aunque cambie el cuerpo).
        $this->assertSame($primero->messageId, $segundo->messageId);

        $otroAsunto = $this->convertir(str_replace('Subject: Sin ID', 'Subject: Otro', $cabeceras), "Hola\n");
        $this->assertNotSame($primero->messageId, $otroAsunto->messageId);
    }

    public function test_el_id_sustituto_no_depende_de_mayusculas_ni_de_la_zona_horaria(): void
    {
        $fecha = CarbonImmutable::parse('2026-11-07 10:00:00', 'UTC');

        $this->assertSame(
            LectorBuzonWebklex::messageIdSustituto('ana@nexus.test', $fecha, 'Hola'),
            LectorBuzonWebklex::messageIdSustituto('ANA@Nexus.test', $fecha->setTimezone('America/Bogota'), 'Hola'),
        );
    }

    /**
     * @return list<string>
     */
    private function archivosEnStorage(): array
    {
        $archivos = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(storage_path(), \FilesystemIterator::SKIP_DOTS)) as $archivo) {
            if (! str_contains($archivo->getPathname(), DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR)
                && ! str_contains($archivo->getPathname(), DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR)) {
                $archivos[] = $archivo->getPathname();
            }
        }
        sort($archivos);

        return $archivos;
    }
}
