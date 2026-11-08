<?php

namespace Tests\Unit;

use App\Support\Correo\CorreoEntrante;
use App\Support\Correo\NormalizadorCorreo;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prueba Unit: NormalizadorCorreo (NP-46 paso 6) es código puro.
 */
class NormalizadorCorreoTest extends TestCase
{
    // ---------- message_id ----------

    public static function messageIdsValidos(): array
    {
        return [
            'normal' => ['abc@nexus.test', 'abc@nexus.test'],
            'con angulos' => ['<abc@nexus.test>', 'abc@nexus.test'],
            'con espacios fuera' => ["  <abc@nexus.test>\t", 'abc@nexus.test'],
            'con tildes' => ['ñandú@nexus.test', 'ñandú@nexus.test'],
            'justo 255' => [str_repeat('a', 255), str_repeat('a', 255)],
        ];
    }

    #[DataProvider('messageIdsValidos')]
    public function test_un_message_id_valido_se_conserva_sin_angulos(string $original, string $esperado): void
    {
        $this->assertSame($esperado, NormalizadorCorreo::messageId($original));
    }

    public static function messageIdsInvalidos(): array
    {
        return [
            'vacio' => [''],
            'solo angulos' => ['<>'],
            '256 caracteres' => [str_repeat('a', 256)],
            '300 caracteres' => [str_repeat('b', 300)],
            'salto de linea' => ["abc\n@nexus.test"],
            'retorno' => ["abc\r@nexus.test"],
            'espacio' => ['abc def@nexus.test'],
            'tabulador' => ["abc\tdef"],
            'nulo' => ["abc\0def"],
            'ESC' => ["abc\e[31mdef"],
            'C1' => ["abc\u{0085}def"],
            'espacio sin corte' => ["abc\u{00A0}def"],
            'bidi' => ["abc\u{202E}def"],
            'utf8 invalido' => ["abc\xC3\x28def"],
        ];
    }

    #[DataProvider('messageIdsInvalidos')]
    public function test_un_message_id_invalido_se_sustituye_por_su_hash(string $original): void
    {
        $resultado = NormalizadorCorreo::messageId($original);

        $this->assertSame('sha256:'.hash('sha256', $original), $resultado);
        $this->assertSame(71, strlen($resultado));
        // Estable: el mismo original da siempre el mismo valor.
        $this->assertSame($resultado, NormalizadorCorreo::messageId($original));
    }

    // ---------- Una línea: asunto y nombre ----------

    public function test_una_linea_quita_controles_ansi_y_bidi(): void
    {
        $this->assertSame(
            'Hola Bcc: x@example.com ROJO facturafdp.exe',
            NormalizadorCorreo::unaLinea("Hola\r\nBcc: x@example.com \e[31mROJO\e[0m\x07 factura\u{202E}fdp.exe\u{2066}", 255)
        );
    }

    public function test_una_linea_colapsa_espacios_y_trunca_con_mb_substr(): void
    {
        $this->assertSame('a b c', NormalizadorCorreo::unaLinea("  a \t\t b \n\n c  ", 255));
        $this->assertSame(str_repeat('é', 150), NormalizadorCorreo::unaLinea(str_repeat('é', 200), 150));
    }

    public function test_una_linea_no_escapa_ni_quita_html(): void
    {
        // Es texto: se escapará al mostrarlo (paso 7), no aquí.
        $this->assertSame('<b>Oferta</b> & más', NormalizadorCorreo::unaLinea('<b>Oferta</b> & más', 255));
    }

    // ---------- from_email ----------

    public function test_el_correo_se_recorta_y_pasa_a_minusculas(): void
    {
        $this->assertSame('ana@nexus.test', NormalizadorCorreo::correo('  Ana@NEXUS.Test '));
        $this->assertSame('ñandú@nexus.test', NormalizadorCorreo::correo('ÑANDÚ@nexus.test'));
    }

    public function test_un_correo_invalido_no_se_descarta_y_se_trunca_a_255(): void
    {
        $this->assertSame('esto no es un correo', NormalizadorCorreo::correo('Esto NO es un correo'));
        $this->assertSame(255, mb_strlen(NormalizadorCorreo::correo(str_repeat('x', 400))));
        $this->assertSame('ana@nexus.test', NormalizadorCorreo::correo("ana@nexus.test\r\n"));
    }

    // ---------- body ----------

    public function test_el_cuerpo_normaliza_saltos_y_quita_controles_salvo_n_y_t(): void
    {
        $this->assertSame(
            "Uno\nDos\nTres\n\tCuatro[31m",
            NormalizadorCorreo::cuerpo("Uno\r\nDos\rTres\0\n\tCuatro\x07\e[31m\x7F")
        );
    }

    public function test_el_cuerpo_guarda_html_como_texto_literal(): void
    {
        $this->assertSame('<script>alert(1)</script>', NormalizadorCorreo::cuerpo('<script>alert(1)</script>'));
    }

    public function test_un_cuerpo_vacio_es_null(): void
    {
        $this->assertNull(NormalizadorCorreo::cuerpo(null));
        $this->assertNull(NormalizadorCorreo::cuerpo("\0\r\n  "));
    }

    public function test_el_cuerpo_nunca_supera_el_limite(): void
    {
        $this->assertSame(CorreoEntrante::LIMITE_TEXTO, mb_strlen(NormalizadorCorreo::cuerpo(str_repeat('a', CorreoEntrante::LIMITE_TEXTO + 10))));
    }

    // ---------- Conjunto ----------

    public function test_para_guardar_no_incluye_is_read_ni_read_at(): void
    {
        $datos = NormalizadorCorreo::paraGuardar(new CorreoEntrante(
            '<id@nexus.test>', 'ANA@nexus.test', '  ', "Asunto\n", 'Texto', CarbonImmutable::parse('2026-11-07 10:00', 'America/Bogota'), true,
        ));

        $this->assertSame(
            ['message_id', 'from_email', 'from_name', 'subject', 'body', 'received_at', 'has_attachments'],
            array_keys($datos)
        );
        $this->assertSame('id@nexus.test', $datos['message_id']);
        $this->assertSame('ana@nexus.test', $datos['from_email']);
        $this->assertNull($datos['from_name']);
        $this->assertSame('Asunto', $datos['subject']);
        $this->assertSame('UTC', $datos['received_at']->getTimezone()->getName());
        $this->assertSame('2026-11-07 15:00:00', $datos['received_at']->format('Y-m-d H:i:s'));
        $this->assertTrue($datos['has_attachments']);
    }
}
