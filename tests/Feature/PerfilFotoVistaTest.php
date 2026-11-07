<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * NP-39 paso 3: interfaz de la foto en la pestaña Perfil (/perfil).
 * El backend (subir, quitar, servir) se prueba en FotoPerfilTest.
 */
class PerfilFotoVistaTest extends TestCase
{
    use RefreshDatabase;

    private const NOMBRE_FOTO = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd.webp';

    /**
     * Subir (POST) y quitar (DELETE) comparten la URL /perfil/avatar: los formularios
     * se distinguen por su _method, no por su action.
     */
    private const FORM_SUBIR = '//form[contains(@class, "perfil-foto-form")]';

    private const FORM_QUITAR = '//form[.//input[@name="_method"][@value="DELETE"]][contains(@class, "perfil-foto-quitar")]';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** Usuario con avatar_path asignado de forma explícita (no está en $fillable). */
    private function usuarioConFoto(array $atributos = []): User
    {
        $usuario = User::factory()->create($atributos);
        $usuario->avatar_path = self::NOMBRE_FOTO;
        $usuario->save();

        return $usuario;
    }

    private function paginaDe(User $usuario): string
    {
        return $this->actingAs($usuario)->get(route('perfil.edit'))->assertOk()->getContent();
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

    private function cuantos(DOMXPath $xpath, string $consulta): int
    {
        return $xpath->query($consulta)->length;
    }

    // a. Sin foto
    public function test_sin_foto_muestra_la_inicial_y_el_formulario_de_subida(): void
    {
        $usuario = User::factory()->create(['name' => 'Ñandú Pérez']);
        $xpath = $this->xpath($this->paginaDe($usuario));

        // La inicial, como antes (mb_ para letras con tilde o ñ).
        $inicial = $this->uno($xpath, '//label[@for="avatar-archivo"]//span[contains(@class, "perfil-avatar")]');
        $this->assertSame('Ñ', $inicial->textContent);
        $this->assertSame('true', $inicial->getAttribute('aria-hidden'));
        $this->assertSame(0, $this->cuantos($xpath, '//img[contains(@class, "avatar-foto")]'));

        $form = $this->uno($xpath, self::FORM_SUBIR);
        $this->assertSame(route('perfil.avatar'), $form->getAttribute('action'));
        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
        // Es POST de verdad: sin _method que lo convierta en otro verbo.
        $this->assertSame(0, $this->cuantos($xpath, self::FORM_SUBIR.'//input[@name="_method"]'));
        $this->assertSame('multipart/form-data', $form->getAttribute('enctype'));
        $this->assertSame(1, $this->cuantos($xpath, self::FORM_SUBIR.'//input[@name="_token"]'));

        $input = $this->uno($xpath, '//input[@id="avatar-archivo"]');
        $this->assertSame('file', $input->getAttribute('type'));
        $this->assertSame('avatar', $input->getAttribute('name'));
        $this->assertSame('image/jpeg,image/png,image/webp', $input->getAttribute('accept'));
        // sr-only (no display:none): sigue recibiendo el foco con Tab.
        $this->assertStringContainsString('sr-only', $input->getAttribute('class'));
        $this->assertSame('avatar-ayuda', $input->getAttribute('aria-describedby'));
        $this->assertFalse($input->hasAttribute('aria-invalid'));
        $this->assertSame('JPG, PNG o WebP. Máximo 2 MB.', trim($this->uno($xpath, '//*[@id="avatar-ayuda"]')->textContent));

        // Nombre accesible del input: el texto de su <label>. La inicial es aria-hidden,
        // así que el nombre lo da el rótulo (con " de perfil" solo para lectores de pantalla).
        $rotulo = $this->uno($xpath, '//label[@for="avatar-archivo"]//*[contains(@class, "perfil-foto-rotulo")]');
        $this->assertSame('Subir foto de perfil', $rotulo->textContent);

        // Botón visible sin JS (app.js lo oculta con una clase).
        $boton = $this->uno($xpath, self::FORM_SUBIR.'//button[@type="submit"]');
        $this->assertSame('Subir foto', trim($boton->textContent));

        // El error existe (para app.js) pero oculto y vacío.
        $error = $this->uno($xpath, '//*[@id="avatar-error"]');
        $this->assertTrue($error->hasAttribute('hidden'));
        $this->assertSame('', trim($error->textContent));

        $this->assertSame(0, $this->cuantos($xpath, self::FORM_QUITAR));
        $this->assertStringNotContainsString('Quitar foto', $xpath->document->saveHTML() ?: '');
    }

    // b. Con foto
    public function test_con_foto_muestra_la_imagen_y_el_formulario_para_quitarla(): void
    {
        $usuario = $this->usuarioConFoto();
        $xpath = $this->xpath($this->paginaDe($usuario));

        $img = $this->uno($xpath, '//label[@for="avatar-archivo"]//img');
        $this->assertSame($usuario->avatarUrl(), $img->getAttribute('src'));
        $this->assertTrue($img->hasAttribute('alt'));
        $this->assertSame('', $img->getAttribute('alt'));
        $this->assertSame('88', $img->getAttribute('width'));
        $this->assertSame('88', $img->getAttribute('height'));
        $this->assertSame('async', $img->getAttribute('decoding'));
        // Está arriba de la página: sin carga diferida.
        $this->assertFalse($img->hasAttribute('loading'));
        $this->assertStringContainsString('perfil-avatar', $img->getAttribute('class'));
        $this->assertStringContainsString('avatar-foto', $img->getAttribute('class'));

        // Ya no hay inicial en el círculo.
        $this->assertSame(0, $this->cuantos($xpath, '//label[@for="avatar-archivo"]//span[contains(@class, "perfil-avatar")]'));
        $this->assertStringContainsString('Cambiar foto', $this->uno($xpath, '//label[@for="avatar-archivo"]')->textContent);

        $quitar = $this->uno($xpath, self::FORM_QUITAR);
        $this->assertSame('POST', strtoupper($quitar->getAttribute('method')));
        $this->assertSame('DELETE', $this->uno($xpath, self::FORM_QUITAR.'//input[@name="_method"]')->getAttribute('value'));
        $this->assertSame(1, $this->cuantos($xpath, self::FORM_QUITAR.'//input[@name="_token"]'));

        $boton = $this->uno($xpath, self::FORM_QUITAR.'//button[@type="submit"]');
        $this->assertSame('Quitar foto', trim($boton->textContent));
        $this->assertSame('¿Quitar tu foto de perfil? Volverá a verse tu inicial.', $boton->getAttribute('data-confirm'));
    }

    // c. Error de la bolsa 'avatar'
    public function test_un_error_de_subida_se_anuncia_y_marca_el_campo(): void
    {
        $usuario = User::factory()->create();

        // Archivo REAL (fileinfo lee el contenido): un .php renombrado a .jpg.
        $ruta = tempnam(sys_get_temp_dir(), 'np39');
        file_put_contents($ruta, "<?php system('id'); ?>");

        try {
            $html = $this->actingAs($usuario)
                ->from(route('perfil.edit'))
                ->followingRedirects()
                ->post(route('perfil.avatar'), ['avatar' => new UploadedFile($ruta, 'foto.jpg', null, null, true)])
                ->assertOk()
                ->getContent();
        } finally {
            @unlink($ruta);
        }

        $xpath = $this->xpath($html);

        $error = $this->uno($xpath, '//*[@id="avatar-error"]');
        $this->assertSame('alert', $error->getAttribute('role'));
        $this->assertFalse($error->hasAttribute('hidden'));
        $this->assertStringContainsString('error', $error->getAttribute('class'));
        $this->assertSame('La foto de perfil debe ser una imagen JPG, PNG o WebP.', trim($error->textContent));

        $input = $this->uno($xpath, '//input[@id="avatar-archivo"]');
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $this->assertSame(['avatar-ayuda', 'avatar-error'], explode(' ', $input->getAttribute('aria-describedby')));

        // El error de la foto no se cuela en los demás formularios.
        $this->assertFalse($this->uno($xpath, '//input[@id="name"]')->hasAttribute('aria-invalid'));
    }

    // d. Escape del nombre
    public function test_un_nombre_con_html_sigue_escapado(): void
    {
        $nombre = '<script>alert(1)</script>"x';

        $sinFoto = $this->paginaDe(User::factory()->create(['name' => $nombre]));
        $this->assertStringNotContainsString('<script>alert(1)', $sinFoto);
        $this->assertStringContainsString('aria-hidden="true">&lt;</span>', $sinFoto);

        $conFoto = $this->paginaDe($this->usuarioConFoto(['name' => $nombre]));
        $this->assertStringNotContainsString('<script>alert(1)', $conFoto);

        // La foto no lleva el nombre en ningún atributo: alt="" siempre.
        $img = $this->uno($this->xpath($conFoto), '//img[contains(@class, "avatar-foto")]');
        $this->assertSame('', $img->getAttribute('alt'));
        foreach ($img->attributes ?? [] as $atributo) {
            $this->assertStringNotContainsString('alert', $atributo->value);
        }
    }

    // e. Sin código en línea (misma comprobación que PerfilTest)
    public function test_la_vista_no_tiene_codigo_en_linea(): void
    {
        foreach ([User::factory()->lector()->create(), $this->usuarioConFoto()] as $usuario) {
            $html = $this->paginaDe($usuario);

            $this->assertStringNotContainsString('style=', $html);
            $this->assertDoesNotMatchRegularExpression('#\son[a-z]+=#i', $html);
            $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#i', $html);
        }
    }

    // g. La foto no añade consultas
    public function test_la_foto_no_anade_consultas(): void
    {
        $sinFoto = User::factory()->lector()->create();
        $conFoto = $this->usuarioConFoto();
        $conFoto->role_id = $sinFoto->role_id;
        $conFoto->save();

        $consultas = [];

        foreach ([$sinFoto, $conFoto] as $usuario) {
            $this->actingAs($usuario);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('perfil.edit'))->assertOk();
            $consultas[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $this->assertSame($consultas[0], $consultas[1], 'Consultas sin foto / con foto: '.implode(', ', $consultas));
    }
}
