<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-39 paso 1 (deuda 19): recursos del layout.
 *
 * - El favicon (SVG + ICO de respaldo) está enlazado y los archivos existen.
 * - Todo lo que el navegador CARGA sale del mismo origen.
 * - El logo sin uso (nexusplays-logo.png, sin "-dark") ya no está en public/.
 *
 * Se prueba en una página pública y en una con sesión iniciada, porque el layout
 * muestra partes distintas en cada caso (por ejemplo el menú del usuario).
 */
class RecursosLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function paginas(): array
    {
        return [
            'pública (inicio)' => ['/', false],
            'con sesión (perfil)' => ['/perfil', true],
        ];
    }

    /** HTML de la página, entrando con un usuario de factory si hace falta. */
    private function html(string $url, bool $conSesion): string
    {
        if ($conSesion) {
            $this->actingAs(User::factory()->create());
        }

        return $this->get($url)->assertOk()->getContent();
    }

    /**
     * Parsea el HTML con DOMDocument en vez de un regex: así no importa el orden
     * de los atributos, las comillas ni los saltos de línea. libxml no conoce las
     * etiquetas de HTML5 (aside, svg, main...) y avisaría por cada una: esos avisos
     * se descartan porque no afectan a la lectura de los atributos.
     */
    private function xpath(string $html): DOMXPath
    {
        $anterior = libxml_use_internal_errors(true);

        $documento = new DOMDocument;
        // El prefijo XML le indica a libxml que el texto viene en UTF-8.
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html);

        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    /**
     * Elementos del XPath como DOMElement (descarta cualquier otro tipo de nodo).
     *
     * @return list<DOMElement>
     */
    private function elementos(DOMXPath $xpath, string $consulta): array
    {
        $resultado = [];

        foreach ($xpath->query($consulta) ?: [] as $nodo) {
            if ($nodo instanceof DOMElement) {
                $resultado[] = $nodo;
            }
        }

        return $resultado;
    }

    /**
     * ¿La URL es del mismo origen? Vale una ruta relativa (sin esquema y sin "//")
     * o una URL que empiece por APP_URL seguida de "/" (o igual a APP_URL).
     * Se exige la "/" para que "http://localhost.otro.com" no pase por "http://localhost".
     */
    private function esMismoOrigen(string $url): bool
    {
        $url = trim($url);

        // "//cdn.com/x.js" es relativa al ESQUEMA, pero apunta a otro dominio.
        if (str_starts_with($url, '//')) {
            return false;
        }

        // Sin esquema (http:, https:, data:...) es una ruta relativa al propio sitio.
        if (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return true;
        }

        $base = rtrim((string) config('app.url'), '/');

        return $url === $base || str_starts_with($url, $base.'/');
    }

    #[DataProvider('paginas')]
    public function test_enlaza_el_favicon_svg_y_el_ico(string $url, bool $conSesion): void
    {
        $xpath = $this->xpath($this->html($url, $conSesion));

        $svg = $this->elementos($xpath, '//link[@rel="icon"][@type="image/svg+xml"]');
        $this->assertCount(1, $svg, 'Falta el <link rel="icon" type="image/svg+xml">.');
        $this->assertSame(asset('favicon.svg'), $svg[0]->getAttribute('href'));

        $hrefsIconos = array_map(
            fn (DOMElement $link) => $link->getAttribute('href'),
            $this->elementos($xpath, '//link[@rel="icon"]'),
        );
        $this->assertContains(asset('favicon.ico'), $hrefsIconos, 'Falta el <link rel="icon"> al favicon.ico.');
    }

    public function test_los_archivos_del_favicon_existen(): void
    {
        $this->assertFileExists(public_path('favicon.svg'));
        $this->assertFileExists(public_path('favicon.ico'));
    }

    /**
     * Deuda 21 / NP-45: la cabecera Cross-Origin-Embedder-Policy: require-corp hace que
     * el navegador BLOQUEE cualquier recurso de otro dominio que no envíe la cabecera
     * Cross-Origin-Resource-Policy (o CORS). Si alguien agrega una imagen, una fuente o
     * un script de un CDN, la página se rompería en silencio en producción. Esta prueba
     * lo detecta antes: todo lo que el navegador carga debe venir del propio sitio.
     *
     * Se revisan los atributos que provocan una descarga: src (img, script, iframe,
     * source, audio, video...), srcset, poster y el href de <link> (stylesheet, icon,
     * preload...). NO se revisa el href de <a>: un enlace a otro sitio no se carga
     * hasta que el usuario hace clic, así que la COEP no lo afecta y está permitido.
     *
     * Ojo: en las pruebas Vite está desactivado (TestCase::withoutVite()), así que el
     * CSS y el JS de @vite no aparecen aquí; Vite los sirve desde el mismo origen
     * (public/build) en producción.
     */
    #[DataProvider('paginas')]
    public function test_todo_lo_que_carga_el_navegador_es_del_mismo_origen(string $url, bool $conSesion): void
    {
        $xpath = $this->xpath($this->html($url, $conSesion));

        $urls = [];

        foreach ($this->elementos($xpath, '//*[@src]') as $elemento) {
            $urls[] = "<{$elemento->tagName} src> ".$elemento->getAttribute('src');
        }

        foreach ($this->elementos($xpath, '//*[@poster]') as $elemento) {
            $urls[] = "<{$elemento->tagName} poster> ".$elemento->getAttribute('poster');
        }

        // srcset: "a.png 1x, b.png 2x" → cada candidato es una URL distinta.
        foreach ($this->elementos($xpath, '//*[@srcset]') as $elemento) {
            foreach (explode(',', $elemento->getAttribute('srcset')) as $candidato) {
                $urls[] = "<{$elemento->tagName} srcset> ".strtok(trim($candidato), ' ');
            }
        }

        foreach ($this->elementos($xpath, '//link[@href]') as $elemento) {
            $urls[] = '<link rel="'.$elemento->getAttribute('rel').'" href> '.$elemento->getAttribute('href');
        }

        // Evita que la prueba pase "en vacío" si el parseo fallara: como mínimo están
        // los dos favicons y el logo de la barra.
        $this->assertGreaterThanOrEqual(3, count($urls), 'No se encontraron recursos: ¿falló el parseo del HTML?');

        $externas = array_values(array_filter(
            $urls,
            fn (string $linea) => ! $this->esMismoOrigen(substr($linea, strpos($linea, '> ') + 2)),
        ));

        $this->assertSame([], $externas, 'Recursos de otro dominio (rompen la COEP require-corp de NP-45).');
    }

    public function test_el_logo_sin_uso_ya_no_existe(): void
    {
        $this->assertFileDoesNotExist(public_path('images/nexusplays-logo.png'));

        // El logo que sí se usa (barra lateral) sigue en su sitio.
        $this->assertFileExists(public_path('images/nexusplays-logo-dark.png'));
    }
}
