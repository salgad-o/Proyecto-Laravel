<?php

namespace Tests\Unit;

use App\Support\SesionesUsuario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prueba Unit: interpretarAgente() es una función pura (no usa base de datos
 * ni HTTP), así que se prueba sin arrancar Laravel.
 */
class InterpretarAgenteTest extends TestCase
{
    public static function agentes(): array
    {
        return [
            'Chrome en Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
                ['Chrome', 'Windows'],
            ],
            'Edge en Windows (también dice Chrome)' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0',
                ['Edge', 'Windows'],
            ],
            'Firefox en Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
                ['Firefox', 'Linux'],
            ],
            'Safari en iPhone (también dice Mac OS X)' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
                ['Safari', 'iOS'],
            ],
        ];
    }

    #[DataProvider('agentes')]
    public function test_reconoce_navegador_y_sistema(string $agente, array $esperado): void
    {
        $this->assertSame($esperado, SesionesUsuario::interpretarAgente($agente));
    }

    public function test_un_agente_vacio_o_nulo_es_desconocido(): void
    {
        $this->assertSame(['Navegador desconocido', null], SesionesUsuario::interpretarAgente(null));
        $this->assertSame(['Navegador desconocido', null], SesionesUsuario::interpretarAgente('   '));
    }

    public function test_un_agente_malicioso_no_se_devuelve_tal_cual(): void
    {
        // Seguridad: solo devuelve textos fijos; nada del user_agent llega a la vista.
        [$navegador, $sistema] = SesionesUsuario::interpretarAgente('<script>alert(1)</script>');

        $this->assertSame('Otro navegador', $navegador);
        $this->assertSame('Otro sistema', $sistema);
    }
}
