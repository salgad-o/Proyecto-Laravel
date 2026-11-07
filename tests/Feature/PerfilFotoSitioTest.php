<?php

namespace Tests\Feature;

use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-39 paso 4: <x-avatar> en el resto del sitio (header, portada, tarjeta,
 * detalle y comentarios). La tarjeta de /perfil se prueba en PerfilFotoVistaTest.
 */
class PerfilFotoSitioTest extends TestCase
{
    use RefreshDatabase;

    private User $sesion;

    private User $autor;

    private User $comentarista;

    private Noticia $noticia;

    /**
     * Una noticia publicada de $autor con un comentario de $comentarista, vista por
     * $sesion. Nombres con iniciales distintas (y con tilde) para no confundirlas.
     */
    private function escenario(bool $conFotos, ?string $nombreParaTodos = null): void
    {
        $this->sesion = User::factory()->lector()->create(['name' => $nombreParaTodos ?? 'Úrsula Sesión']);
        $this->autor = User::factory()->editor()->create(['name' => $nombreParaTodos ?? 'Óscar Autor']);
        $this->comentarista = User::factory()->lector()->create(['name' => $nombreParaTodos ?? 'Carla Comenta']);

        $this->noticia = Noticia::factory()->for($this->autor)->create();
        Comentario::factory()->for($this->noticia)->for($this->comentarista)->create();

        if ($conFotos) {
            foreach ([$this->sesion, $this->autor, $this->comentarista] as $i => $usuario) {
                $this->ponerFoto($usuario, $i);
            }
        }

        $this->actingAs($this->sesion);
    }

    /** Asignación explícita: avatar_path no está en $fillable. */
    private function ponerFoto(User $usuario, int $semilla): void
    {
        $usuario->avatar_path = substr(str_repeat(md5((string) $semilla.$usuario->id), 2), 0, 40).'.webp';
        $usuario->save();
    }

    private function xpath(string $html): DOMXPath
    {
        $anterior = libxml_use_internal_errors(true);
        $documento = new DOMDocument;
        $documento->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new DOMXPath($documento);
    }

    private function uno(DOMXPath $xpath, string $consulta): DOMElement
    {
        $nodos = $xpath->query($consulta);
        $this->assertNotFalse($nodos);
        $this->assertSame(1, $nodos->length, "Se esperaba exactamente un nodo para: {$consulta}");
        $nodo = $nodos->item(0);
        $this->assertInstanceOf(DOMElement::class, $nodo);

        return $nodo;
    }

    /**
     * Texto que lee un lector de pantalla (simplificado): ignora lo que tiene
     * aria-hidden="true" y usa el alt de las imágenes.
     */
    private function textoAccesible(DOMNode $nodo): string
    {
        if ($nodo instanceof DOMElement) {
            if ($nodo->getAttribute('aria-hidden') === 'true') {
                return '';
            }

            if ($nodo->tagName === 'img') {
                return $nodo->getAttribute('alt');
            }
        }

        if (! $nodo->hasChildNodes()) {
            return $nodo->nodeType === XML_TEXT_NODE ? (string) $nodo->textContent : '';
        }

        $texto = '';
        foreach ($nodo->childNodes as $hijo) {
            $texto .= $this->textoAccesible($hijo);
        }

        return $texto;
    }

    /**
     * Los 5 lugares: [página, de quién es el avatar, contenedor (XPath), clase, lazy].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool}>
     */
    public static function lugares(): array
    {
        return [
            'header' => ['inicio', 'sesion', '//a[contains(@class, "sesion-perfil")]', 'sesion-avatar', false],
            // Sin lazy: la destacada está en la primera pantalla (ajuste 4b).
            'portada (destacada)' => ['inicio', 'autor', '//div[contains(@class, "destacada-meta")]', 'autor-avatar', false],
            'tarjeta (lista)' => ['lista', 'autor', '//article[contains(@class, "tarjeta")]//div[contains(@class, "tarjeta-pie")]', 'autor-avatar', true],
            'detalle (autor)' => ['detalle', 'autor', '//div[contains(@class, "detalle-meta")]', 'autor-avatar', false],
            'comentario' => ['detalle', 'comentarista', '//article[contains(@class, "comentario")]//div[contains(@class, "comentario-meta")]', 'autor-avatar', true],
        ];
    }

    private function html(string $pagina): string
    {
        $url = match ($pagina) {
            'inicio' => route('home'),
            'lista' => route('noticias.index'),
            'detalle' => route('noticias.show', $this->noticia),
        };

        return $this->get($url)->assertOk()->getContent();
    }

    #[DataProvider('lugares')]
    public function test_con_foto_cada_lugar_muestra_la_imagen(string $pagina, string $quien, string $contenedor, string $clase, bool $lazy): void
    {
        $this->escenario(conFotos: true);
        $usuario = $this->{$quien};

        $img = $this->uno($this->xpath($this->html($pagina)), "{$contenedor}//img");

        $this->assertSame($usuario->avatarUrl(), $img->getAttribute('src'));
        $this->assertTrue($img->hasAttribute('alt'));
        $this->assertSame('', $img->getAttribute('alt'));
        // 36 px: el tamaño CSS de .sesion-avatar y .autor-avatar (sin cambios en móvil).
        $this->assertSame('36', $img->getAttribute('width'));
        $this->assertSame('36', $img->getAttribute('height'));
        $this->assertSame('async', $img->getAttribute('decoding'));
        $this->assertStringContainsString($clase, $img->getAttribute('class'));
        $this->assertStringContainsString('avatar-foto', $img->getAttribute('class'));
        $this->assertSame($lazy ? 'lazy' : '', $img->getAttribute('loading'));
    }

    #[DataProvider('lugares')]
    public function test_sin_foto_cada_lugar_muestra_la_inicial(string $pagina, string $quien, string $contenedor, string $clase, bool $lazy): void
    {
        $this->escenario(conFotos: false);
        $usuario = $this->{$quien};
        $xpath = $this->xpath($this->html($pagina));

        $inicial = $this->uno($xpath, "{$contenedor}//span[contains(concat(' ', normalize-space(@class), ' '), ' {$clase} ')]");
        $this->assertSame(mb_strtoupper(mb_substr($usuario->name, 0, 1)), $inicial->textContent);
        $this->assertSame('true', $inicial->getAttribute('aria-hidden'));
        $this->assertSame(0, $xpath->query("{$contenedor}//img")->length);
    }

    /**
     * La rejilla de la portada empieza en la SEGUNDA noticia más reciente: hace falta
     * otra noticia, más antigua, para que haya una tarjeta. Sus avatares sí son lazy.
     */
    public function test_las_tarjetas_de_la_rejilla_de_la_portada_usan_lazy(): void
    {
        $this->escenario(conFotos: true);
        $autorRejilla = User::factory()->editor()->create(['name' => 'Rocío Rejilla']);
        $this->ponerFoto($autorRejilla, 9);
        Noticia::factory()->for($autorRejilla)->create(['published_at' => $this->noticia->published_at->subDay()]);

        $xpath = $this->xpath($this->html('inicio'));

        $img = $this->uno($xpath, '//article[contains(@class, "tarjeta")]//div[contains(@class, "tarjeta-pie")]//img');
        $this->assertSame($autorRejilla->avatarUrl(), $img->getAttribute('src'));
        $this->assertSame('lazy', $img->getAttribute('loading'));
        $this->assertSame('', $img->getAttribute('alt'));

        // La destacada, en la misma página, sigue sin lazy.
        $destacada = $this->uno($xpath, '//div[contains(@class, "destacada-meta")]//img');
        $this->assertSame($this->autor->avatarUrl(), $destacada->getAttribute('src'));
        $this->assertFalse($destacada->hasAttribute('loading'));
    }

    public function test_el_enlace_del_header_se_llama_como_el_usuario_con_y_sin_foto(): void
    {
        foreach ([false, true] as $conFotos) {
            $this->escenario($conFotos);
            $enlace = $this->uno($this->xpath($this->html('inicio')), '//a[contains(@class, "sesion-perfil")]');

            $this->assertSame(route('perfil.edit'), $enlace->getAttribute('href'));
            $this->assertSame('Úrsula Sesión', trim($this->textoAccesible($enlace)));
            $this->assertFalse($enlace->hasAttribute('aria-label'));
        }
    }

    public function test_un_nombre_con_html_sigue_escapado_en_todos_los_lugares(): void
    {
        $nombre = '<script>alert(1)</script>';

        foreach ([false, true] as $conFotos) {
            $this->escenario($conFotos, $nombre);

            foreach (['inicio', 'lista', 'detalle'] as $pagina) {
                $html = $this->html($pagina);
                $this->assertStringNotContainsString('<script>alert(1)', $html, "Sin escapar en {$pagina}.");
                $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

                foreach ($this->xpath($html)->query('//img[contains(@class, "avatar-foto")]') ?: [] as $img) {
                    $this->assertInstanceOf(DOMElement::class, $img);
                    $this->assertSame('', $img->getAttribute('alt'));
                    foreach ($img->attributes ?? [] as $atributo) {
                        $this->assertStringNotContainsString('alert', $atributo->value);
                    }
                }

                if (! $conFotos) {
                    $this->assertStringContainsString('aria-hidden="true">&lt;</span>', $html);
                }
            }
        }
    }

    public function test_las_vistas_siguen_sin_codigo_en_linea(): void
    {
        $this->escenario(conFotos: true);

        foreach (['inicio', 'lista', 'detalle'] as $pagina) {
            $html = $this->html($pagina);

            $this->assertStringNotContainsString('style=', $html);
            $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
            $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
        }
    }

    // ----- Sin N+1 CON fotos: las consultas no crecen con las filas -----

    private function contarConsultas(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    private function contarFotos(string $url): int
    {
        return $this->xpath($this->get($url)->getContent())->query('//img[contains(@class, "autor-avatar")]')->length;
    }

    /** Noticias publicadas, cada una de un autor distinto CON foto. */
    private function noticiasConAutorConFoto(int $cuantas): void
    {
        Noticia::factory()->count($cuantas)->create()->each(fn (Noticia $n) => $this->ponerFoto($n->user, 1));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function listados(): array
    {
        return [
            'portada' => ['home'],
            'lista de noticias' => ['noticias.index'],
        ];
    }

    #[DataProvider('listados')]
    public function test_listados_sin_n_mas_1_con_autores_con_foto(string $ruta): void
    {
        $url = route($ruta);

        $this->noticiasConAutorConFoto(2);
        $conDos = $this->contarConsultas($url);
        $fotosConDos = $this->contarFotos($url);

        $this->noticiasConAutorConFoto(5);
        $conSiete = $this->contarConsultas($url);

        $this->assertSame($conDos, $conSiete, "Las consultas crecen con las filas en {$url}.");
        // Las fotos se dibujan de verdad (no se cae a la inicial).
        $this->assertGreaterThan(0, $fotosConDos);
        $this->assertGreaterThan($fotosConDos, $this->contarFotos($url));
    }

    public function test_detalle_sin_n_mas_1_con_comentaristas_con_foto(): void
    {
        $noticia = Noticia::factory()->create();
        $this->ponerFoto($noticia->user, 1);

        $comentar = fn (int $cuantos) => Comentario::factory()->count($cuantos)->for($noticia)->create()
            ->each(fn (Comentario $c) => $this->ponerFoto($c->user, 2));

        $comentar(2);
        $url = route('noticias.show', $noticia);
        $conDos = $this->contarConsultas($url);
        $this->assertSame(3, $this->contarFotos($url)); // autor + 2 comentaristas

        $comentar(8);
        $this->assertSame($conDos, $this->contarConsultas($url));
        $this->assertSame(11, $this->contarFotos($url));
    }

    public function test_el_header_con_foto_no_anade_consultas(): void
    {
        Noticia::factory()->create();
        $usuario = User::factory()->lector()->create();

        $this->actingAs($usuario->fresh());
        $sinFoto = $this->contarConsultas(route('home'));

        $this->ponerFoto($usuario, 3);
        $this->actingAs($usuario->fresh());
        $this->assertSame($sinFoto, $this->contarConsultas(route('home')));
    }
}
