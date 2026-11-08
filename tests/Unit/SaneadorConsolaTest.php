<?php

namespace Tests\Unit;

use App\Support\Correo\SaneadorConsola;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Prueba Unit: SaneadorConsola (D9) es una función pura.
 */
class SaneadorConsolaTest extends TestCase
{
    public static function entradas(): array
    {
        return [
            'color ANSI' => ["\e[31mROJO\e[0m", 'ROJO'],
            'borrar pantalla' => ["\e[2J\e[HHola", 'Hola'],
            'enlace OSC 8 con BEL' => ["\e]8;;http://malo.test\x07clic\e]8;;\x07", 'clic'],
            'titulo OSC con ST' => ["\e]0;titulo falso\e\\Hola", 'Hola'],
            // "ESC B" sería una secuencia completa (la terminal se come la B); "ESC 1" no.
            'ESC suelto' => ["A\e1B", 'A1B'],
            'secuencia de dos caracteres' => ["A\eBC", 'AC'],
            'campana' => ["Hola\x07", 'Hola'],
            'nulo' => ["Ho\x00la", 'Hola'],
            'saltos y tabs' => ["Uno\r\nDos\nTres\tCuatro", 'Uno Dos Tres Cuatro'],
            'DEL' => ["Ho\x7Fla", 'Hola'],
            'CSI de 8 bits (U+009B)' => ["Ho\u{009B}31mla", 'Ho31mla'],
            'bidi RLO' => ["factura\u{202E}fdp.exe", 'facturafdp.exe'],
            'tildes intactas' => ['Ñandú y café', 'Ñandú y café'],
            'nulo como valor' => [null, ''],
        ];
    }

    #[DataProvider('entradas')]
    public function test_quita_controles_y_secuencias(?string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, SaneadorConsola::linea($entrada, 150));
    }

    public function test_trunca_al_maximo_con_puntos_suspensivos(): void
    {
        $resultado = SaneadorConsola::linea(str_repeat('é', 300), 150);

        $this->assertSame(150, mb_strlen($resultado));
        $this->assertStringEndsWith('…', $resultado);
    }

    public function test_un_texto_dentro_del_limite_no_se_toca(): void
    {
        $this->assertSame(str_repeat('a', 150), SaneadorConsola::linea(str_repeat('a', 150), 150));
    }

    public function test_escapa_las_etiquetas_de_estilo_de_symfony(): void
    {
        $saneado = SaneadorConsola::linea('<fg=red>ROJO</> <href=http://malo.test>clic</>', 150);

        // Al pasar por el formateador de la consola (sin colores) sale tal cual, como texto.
        $this->assertSame(
            '<fg=red>ROJO</> <href=http://malo.test>clic</>',
            (new OutputFormatter(false))->format($saneado)
        );
    }

    public function test_utf8_invalido_no_rompe(): void
    {
        $resultado = SaneadorConsola::linea("Hola \xC3\x28", 150);

        $this->assertTrue(mb_check_encoding($resultado, 'UTF-8'));
        $this->assertStringStartsWith('Hola', $resultado);
    }
}
