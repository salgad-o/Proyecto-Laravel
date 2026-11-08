<?php

namespace Tests\Unit;

use App\Support\Correo\CorreoEntrante;
use PHPUnit\Framework\TestCase;

/**
 * Prueba Unit: CorreoEntrante y la conversión HTML → texto son código puro
 * (sin base de datos ni red), así que se prueban sin arrancar Laravel.
 */
class CorreoEntranteTest extends TestCase
{
    private function correo(?string $texto, string $messageId = 'id@nexus.test'): CorreoEntrante
    {
        return new CorreoEntrante($messageId, 'ana@nexus.test', null, null, $texto, null, false);
    }

    public function test_html_a_texto_sin_etiquetas_ni_script_y_con_entidades_decodificadas(): void
    {
        $texto = CorreoEntrante::textoDesdeHtml('<script>alert(1)</script><p>Hola &amp; adiós</p>');

        $this->assertSame('Hola & adiós', $texto);
        $this->assertStringNotContainsString('<', $texto);
        $this->assertStringNotContainsString('alert', $texto);
        $this->assertStringNotContainsString('&amp;', $texto);
    }

    public function test_html_a_texto_quita_style_y_respeta_los_saltos(): void
    {
        $html = '<style>p{color:red}</style><div>Línea uno</div><p>Línea dos<br>Línea tres</p><SCRIPT type="x">malo()</SCRIPT >';

        $this->assertSame("Línea uno\nLínea dos\nLínea tres", CorreoEntrante::textoDesdeHtml($html));
    }

    public function test_html_a_texto_no_reinterpreta_entidades_como_etiquetas(): void
    {
        // "&lt;b&gt;" es texto que el remitente quería mostrar: queda como "<b>" literal
        // (TEXTO, no HTML; al mostrarlo en el panel se escapará con {{ }}).
        $this->assertSame('Escribe <b> para negrita', CorreoEntrante::textoDesdeHtml('<p>Escribe &lt;b&gt; para negrita</p>'));
    }

    public function test_texto_prefiere_la_parte_plana(): void
    {
        $this->assertSame('Versión plana', CorreoEntrante::textoDe('Versión plana', '<p>Versión HTML</p>'));
        $this->assertSame('Versión HTML', CorreoEntrante::textoDe(null, '<p>Versión HTML</p>'));
        $this->assertSame('Versión HTML', CorreoEntrante::textoDe("  \n ", '<p>Versión HTML</p>'));
        $this->assertNull(CorreoEntrante::textoDe(null, null));
        $this->assertNull(CorreoEntrante::textoDe('', '   '));
    }

    public function test_un_texto_mayor_al_limite_se_trunca(): void
    {
        $largo = str_repeat('á', CorreoEntrante::LIMITE_TEXTO + 500);

        $this->assertSame(CorreoEntrante::LIMITE_TEXTO, mb_strlen($this->correo($largo)->texto));
        $this->assertSame(CorreoEntrante::LIMITE_TEXTO, mb_strlen(CorreoEntrante::textoDe($largo, null)));
        $this->assertSame(CorreoEntrante::LIMITE_TEXTO, mb_strlen(CorreoEntrante::textoDesdeHtml('<p>'.$largo.'</p>')));
    }

    public function test_el_texto_queda_en_utf8_valido(): void
    {
        $texto = $this->correo("Hola \xC3\x28 mundo")->texto;

        $this->assertTrue(mb_check_encoding($texto, 'UTF-8'));
        $this->assertStringStartsWith('Hola ', $texto);
    }

    public function test_el_message_id_se_guarda_sin_angulos(): void
    {
        $this->assertSame('abc@nexus.test', $this->correo('x', ' <abc@nexus.test> ')->messageId);
    }

    public function test_es_inmutable(): void
    {
        $correo = $this->correo('x');

        $this->expectException(\Error::class);
        $correo->asunto = 'cambiado';
    }
}
