<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\ProcesadorAvatar;
use Closure;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-39 paso 2: subir, quitar y servir la foto de perfil.
 *
 * OJO: se usan UploadedFile REALES (archivos temporales con test = true) y no
 * UploadedFile::fake(). El fake de Laravel deduce el tipo MIME del NOMBRE del
 * archivo, así que un .php llamado "foto.jpg" pasaría mimes/mimetypes en la prueba
 * aunque en producción fallara. Con archivos reales, fileinfo lee los bytes, igual
 * que en una subida de verdad.
 */
class FotoPerfilTest extends TestCase
{
    use RefreshDatabase;

    private const NOMBRE_VALIDO = '/^[A-Za-z0-9]{40}\.webp$/';

    /** Archivos temporales creados por la prueba (se borran en tearDown). */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Ayudantes para construir imágenes y archivos
    // ---------------------------------------------------------------------

    /**
     * Bytes de una imagen real generada con GD.
     *
     * @param  'jpeg'|'png'|'webp'  $tipo
     * @param  (Closure(GdImage): void)|null  $pintar
     */
    private static function imagen(string $tipo, int $ancho = 300, int $alto = 200, ?Closure $pintar = null): string
    {
        $gd = imagecreatetruecolor($ancho, $alto);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 120, 60, 200));

        if ($pintar) {
            $pintar($gd);
        }

        ob_start();
        match ($tipo) {
            'jpeg' => imagejpeg($gd, null, 95),
            'png' => imagepng($gd),
            'webp' => imagewebp($gd, null, 90),
        };

        return (string) ob_get_clean();
    }

    /**
     * JPEG real con un segmento APP1 (EXIF) insertado justo después del SOI (FF D8).
     * Reutilizable: $carga es lo que va dentro del APP1, empezando por "Exif\0\0".
     */
    private static function jpegConApp1(string $carga, int $ancho = 300, int $alto = 200, ?Closure $pintar = null): string
    {
        $jpeg = self::imagen('jpeg', $ancho, $alto, $pintar);

        // La longitud del segmento cuenta sus propios 2 bytes de longitud (big-endian).
        $app1 = "\xFF\xE1".pack('n', strlen($carga) + 2).$carga;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    /** EXIF mínimo y válido (TIFF big-endian) con solo la etiqueta Orientation. */
    private static function exifOrientacion(int $orientacion): string
    {
        return "Exif\0\0"
            ."MM\x00\x2A\x00\x00\x00\x08"       // cabecera TIFF, IFD0 en el byte 8
            ."\x00\x01"                          // 1 entrada
            ."\x01\x12\x00\x03\x00\x00\x00\x01"  // 0x0112 Orientation, SHORT, 1 valor
            .pack('n', $orientacion)."\x00\x00"
            ."\x00\x00\x00\x00";                 // no hay más IFD
    }

    /** Subida real (fileinfo lee el contenido) con el nombre indicado. */
    private function archivo(string $bytes, string $nombre, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'np39');
        file_put_contents($ruta, $bytes);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, null, $error, true);
    }

    private function subir(User $usuario, UploadedFile $archivo)
    {
        return $this->actingAs($usuario)->post(route('perfil.avatar'), ['avatar' => $archivo]);
    }

    /** Contenido del archivo guardado del usuario. */
    private function guardado(User $usuario): string
    {
        $usuario->refresh();
        $this->assertNotNull($usuario->avatar_path);

        return (string) Storage::disk('local')->get("avatares/{$usuario->avatar_path}");
    }

    /** Comprueba que el intento no dejó rastro: ni archivo, ni cambio en BD, ni auditoría. */
    private function assertSinCambios(User $usuario, ?string $avatarAntes = null): void
    {
        $this->assertSame($avatarAntes, $usuario->fresh()->avatar_path);
        $this->assertSame($avatarAntes ? ["avatares/{$avatarAntes}"] : [], Storage::disk('local')->files('avatares'));
        $this->assertSame(0, AuditLog::where('action', 'cambiar_avatar')->count());
    }

    // ---------------------------------------------------------------------
    // a, b: subidas válidas
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function imagenesValidas(): array
    {
        return [
            'JPG' => ['jpeg', 'foto.jpg'],
            'PNG' => ['png', 'foto.png'],
            'WebP' => ['webp', 'foto.webp'],
        ];
    }

    #[DataProvider('imagenesValidas')]
    public function test_una_imagen_valida_se_guarda_como_webp_de_256(string $tipo, string $nombre): void
    {
        $usuario = User::factory()->create();

        $this->subir($usuario, $this->archivo(self::imagen($tipo), $nombre))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('status', 'Foto de perfil actualizada.')
            ->assertSessionHasNoErrors();

        $usuario->refresh();
        $this->assertMatchesRegularExpression(self::NOMBRE_VALIDO, $usuario->avatar_path);
        Storage::disk('local')->assertExists("avatares/{$usuario->avatar_path}");

        $info = getimagesizefromstring($this->guardado($usuario));
        $this->assertNotFalse($info);
        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame([ProcesadorAvatar::LADO, ProcesadorAvatar::LADO], [$info[0], $info[1]]);
    }

    public function test_un_png_transparente_conserva_el_canal_alfa(): void
    {
        $usuario = User::factory()->create();

        // Todo transparente salvo un cuadrado opaco en el centro.
        $png = self::imagen('png', 200, 200, function (GdImage $gd) {
            imagealphablending($gd, false);
            imagesavealpha($gd, true);
            imagefill($gd, 0, 0, imagecolorallocatealpha($gd, 0, 0, 0, 127));
            imagefilledrectangle($gd, 80, 80, 120, 120, imagecolorallocatealpha($gd, 255, 0, 0, 0));
        });

        $this->subir($usuario, $this->archivo($png, 'transparente.png'))->assertSessionHasNoErrors();

        $resultado = imagecreatefromstring($this->guardado($usuario));
        $this->assertNotFalse($resultado);

        // En GD el alfa va de 0 (opaco) a 127 (transparente).
        $alfaEsquina = (imagecolorat($resultado, 5, 5) >> 24) & 0x7F;
        $alfaCentro = (imagecolorat($resultado, 128, 128) >> 24) & 0x7F;

        $this->assertGreaterThan(100, $alfaEsquina, 'La esquina debería seguir transparente.');
        $this->assertLessThan(20, $alfaCentro, 'El centro debería seguir opaco.');
    }

    // ---------------------------------------------------------------------
    // c, d, e, f: archivos rechazados por el FormRequest
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: Closure(): string, 1: string}>
     */
    public static function archivosRechazados(): array
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><script>alert(1)</script></svg>';

        return [
            'texto plano' => [fn () => 'Hola, esto no es una imagen.', 'nota.txt'],
            'PDF' => [fn () => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF", 'documento.pdf'],
            'PHP renombrado a .jpg' => [fn () => "<?php system('id'); ?>", 'foto.jpg'],
            'SVG con script' => [fn () => $svg, 'dibujo.svg'],
            'SVG renombrado a .png' => [fn () => $svg, 'dibujo.png'],
            // PNG válido de 300×200 relleno hasta pasar de 2 MB.
            'más de 2 MB' => [fn () => self::imagen('png').str_repeat("\0", 2 * 1024 * 1024 + 1024), 'grande.png'],
            'menos de 100×100' => [fn () => self::imagen('png', 99, 99), 'pequena.png'],
            'más de 4000 de ancho' => [fn () => self::imagen('png', 4001, 100), 'ancha.png'],
            'más de 4000 de alto' => [fn () => self::imagen('png', 100, 4001), 'alta.png'],
            'GIF (no está en la lista)' => [function () {
                $gd = imagecreatetruecolor(200, 200);
                ob_start();
                imagegif($gd);

                return (string) ob_get_clean();
            }, 'animacion.gif'],
        ];
    }

    /**
     * @param  Closure(): string  $bytes
     */
    #[DataProvider('archivosRechazados')]
    public function test_un_archivo_no_permitido_se_rechaza_sin_dejar_rastro(Closure $bytes, string $nombre): void
    {
        $usuario = User::factory()->create();

        $this->from(route('perfil.edit'))
            ->subir($usuario, $this->archivo($bytes(), $nombre))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHasErrorsIn('avatar', 'avatar');

        $this->assertSinCambios($usuario);
    }

    public function test_los_mensajes_son_en_espanol(): void
    {
        $usuario = User::factory()->create();

        $casos = [
            [$this->archivo('texto', 'nota.txt'), 'La foto de perfil debe ser una imagen JPG, PNG o WebP.'],
            [$this->archivo(self::imagen('png', 99, 99), 'p.png'), 'La foto de perfil debe medir entre 100 y 4000 píxeles de ancho y de alto.'],
            [$this->archivo(self::imagen('png').str_repeat("\0", 2 * 1024 * 1024 + 1024), 'g.png'), 'La foto de perfil debe pesar como máximo 2 MB.'],
            // PHP rechazó el archivo antes de Laravel (upload_max_filesize).
            [$this->archivo(self::imagen('png'), 'f.png', UPLOAD_ERR_INI_SIZE), 'No pudimos recibir la imagen. Debe pesar como máximo 2 MB.'],
        ];

        foreach ($casos as [$archivo, $mensaje]) {
            $this->subir($usuario, $archivo);
            $errores = session('errors')->getBag('avatar')->get('avatar');
            $this->assertContains($mensaje, $errores);
        }

        $this->actingAs($usuario)->post(route('perfil.avatar'), [])
            ->assertSessionHasErrorsIn('avatar', ['avatar' => 'Elige una imagen para tu foto de perfil.']);

        $this->assertSinCambios($usuario);
    }

    // ---------------------------------------------------------------------
    // g, h: la recodificación quita lo que no son píxeles
    // ---------------------------------------------------------------------

    public function test_un_poliglota_png_php_se_acepta_pero_sin_el_codigo(): void
    {
        $usuario = User::factory()->create();
        $poliglota = self::imagen('png')."<?php echo 'pwned'; ?>";

        $this->subir($usuario, $this->archivo($poliglota, 'poliglota.png'))->assertSessionHasNoErrors();

        $guardado = $this->guardado($usuario);
        $this->assertStringNotContainsString('<?php', $guardado);
        $this->assertStringNotContainsString('pwned', $guardado);
    }

    public function test_los_metadatos_exif_y_gps_se_eliminan(): void
    {
        $usuario = User::factory()->create();
        $jpeg = self::jpegConApp1("Exif\0\0GPS-PRUEBA-NP39");

        // La foto de prueba SÍ lleva los metadatos antes de subirla.
        $this->assertStringContainsString('GPS-PRUEBA-NP39', $jpeg);

        $this->subir($usuario, $this->archivo($jpeg, 'con-gps.jpg'))->assertSessionHasNoErrors();

        $guardado = $this->guardado($usuario);
        $this->assertStringNotContainsString('Exif', $guardado);
        $this->assertStringNotContainsString('GPS-PRUEBA-NP39', $guardado);
    }

    /**
     * Orientation = 6: la cámara guardó la foto acostada y hay que girarla 90° a la
     * derecha. Foto de 200×100 con la mitad izquierda roja y la derecha azul: girada,
     * el rojo queda ARRIBA y el azul ABAJO. Sin el giro, quedarían a izquierda y derecha.
     */
    public function test_se_aplica_la_orientacion_exif_antes_de_recortar(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('La extensión exif no está instalada.');
        }

        $usuario = User::factory()->create();
        $jpeg = self::jpegConApp1(self::exifOrientacion(6), 200, 100, function (GdImage $gd) {
            imagefilledrectangle($gd, 0, 0, 99, 99, imagecolorallocate($gd, 255, 0, 0));
            imagefilledrectangle($gd, 100, 0, 199, 99, imagecolorallocate($gd, 0, 0, 255));
        });

        $this->subir($usuario, $this->archivo($jpeg, 'acostada.jpg'))->assertSessionHasNoErrors();

        $resultado = imagecreatefromstring($this->guardado($usuario));
        $arriba = imagecolorsforindex($resultado, imagecolorat($resultado, 128, 30));
        $abajo = imagecolorsforindex($resultado, imagecolorat($resultado, 128, 226));

        $this->assertGreaterThan($arriba['blue'], $arriba['red'], 'Arriba debería ser rojo.');
        $this->assertGreaterThan($abajo['red'], $abajo['blue'], 'Abajo debería ser azul.');
    }

    // ---------------------------------------------------------------------
    // i, j: reemplazar, quitar y auditoría
    // ---------------------------------------------------------------------

    public function test_reemplazar_y_quitar_borran_el_archivo_anterior(): void
    {
        $usuario = User::factory()->create();

        $this->subir($usuario, $this->archivo(self::imagen('png'), 'a.png'));
        $primera = $usuario->fresh()->avatar_path;

        $this->subir($usuario, $this->archivo(self::imagen('jpeg'), 'b.jpg'));
        $segunda = $usuario->fresh()->avatar_path;

        $this->assertNotSame($primera, $segunda);
        Storage::disk('local')->assertMissing("avatares/{$primera}");
        Storage::disk('local')->assertExists("avatares/{$segunda}");

        $this->actingAs($usuario)->delete(route('perfil.avatar.quitar'))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('status', 'Quitamos tu foto de perfil.');

        $this->assertNull($usuario->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('local')->files('avatares'));
    }

    public function test_quitar_sin_foto_no_audita(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->delete(route('perfil.avatar.quitar'))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHas('status', 'No tenías foto de perfil.');

        $this->assertSame(0, AuditLog::where('action', 'cambiar_avatar')->count());
    }

    public function test_subir_cambiar_y_quitar_se_auditan_sin_el_nombre_del_archivo(): void
    {
        $usuario = User::factory()->create();
        AuditLog::query()->delete(); // ya hay un 'crear' de la factory

        $this->subir($usuario, $this->archivo(self::imagen('png'), 'a.png'));
        $primera = $usuario->fresh()->avatar_path;
        $this->subir($usuario, $this->archivo(self::imagen('png'), 'b.png'));
        $segunda = $usuario->fresh()->avatar_path;
        $this->actingAs($usuario)->delete(route('perfil.avatar.quitar'));

        $registros = AuditLog::orderBy('id')->get();

        $this->assertSame(
            [
                ['cambiar_avatar', ['foto' => 'sin foto'], ['foto' => 'con foto']],
                ['cambiar_avatar', ['foto' => 'con foto'], ['foto' => 'con foto']],
                ['cambiar_avatar', ['foto' => 'con foto'], ['foto' => 'sin foto']],
            ],
            $registros->map(fn (AuditLog $r) => [$r->action, $r->old_values, $r->new_values])->all(),
        );

        foreach ($registros as $registro) {
            $this->assertSame($usuario->id, $registro->user_id);
            $this->assertSame($usuario->getMorphClass(), $registro->auditable_type);
            $this->assertSame($usuario->id, $registro->auditable_id);

            $valores = json_encode([$registro->old_values, $registro->new_values]);
            $this->assertStringNotContainsString($primera, $valores);
            $this->assertStringNotContainsString($segunda, $valores);
            $this->assertStringNotContainsString('avatar_path', $valores);
        }

        $this->assertSame(0, AuditLog::where('action', 'editar')->count());
    }

    public function test_la_accion_aparece_en_la_auditoria_con_sus_valores(): void
    {
        $admin = User::factory()->admin()->create();
        $this->subir($admin, $this->archivo(self::imagen('png'), 'a.png'));

        $this->actingAs($admin)->get(route('auditoria.index', ['accion' => 'cambiar_avatar']))
            ->assertOk()
            ->assertSee('<option value="cambiar_avatar" selected>Cambió foto de perfil</option>', false)
            ->assertSeeInOrder(['<strong>foto</strong>', 'sin foto →', 'con foto'], false);
    }

    // ---------------------------------------------------------------------
    // k, l, n: asignación masiva, visitantes y roles
    // ---------------------------------------------------------------------

    public function test_avatar_path_no_se_puede_asignar_desde_otro_formulario(): void
    {
        $usuario = User::factory()->create();
        $victima = User::factory()->create();
        $victima->avatar_path = str_repeat('a', 40).'.webp';
        $victima->save();

        $this->actingAs($usuario)->patch(route('perfil.nombre'), [
            'name' => 'Nombre nuevo',
            'avatar_path' => $victima->avatar_path,
        ])->assertSessionHasNoErrors();

        $usuario->refresh();
        $this->assertSame('Nombre nuevo', $usuario->name);
        $this->assertNull($usuario->avatar_path);
    }

    public function test_un_visitante_no_puede_subir_ni_quitar_fotos(): void
    {
        $this->post(route('perfil.avatar'), ['avatar' => $this->archivo(self::imagen('png'), 'a.png')])
            ->assertRedirect(route('login'));
        $this->delete(route('perfil.avatar.quitar'))->assertRedirect(route('login'));

        $this->assertSame([], Storage::disk('local')->files('avatares'));
    }

    public function test_lector_editor_y_admin_pueden_subir_su_foto(): void
    {
        $usuarios = [
            'lector' => User::factory()->lector()->create(),
            'editor' => User::factory()->editor()->create(),
            'admin' => User::factory()->admin()->create(),
        ];

        foreach ($usuarios as $rol => $usuario) {
            $this->assertTrue($usuario->hasRole($rol));

            $this->subir($usuario, $this->archivo(self::imagen('png'), 'a.png'))
                ->assertRedirect(route('perfil.edit'))
                ->assertSessionHasNoErrors();

            $this->assertNotNull($usuario->fresh()->avatar_path, "El {$rol} debería tener foto.");
        }
    }

    // ---------------------------------------------------------------------
    // m: servir la foto
    // ---------------------------------------------------------------------

    public function test_la_foto_vigente_se_sirve_con_cabeceras_seguras(): void
    {
        $usuario = User::factory()->create();
        $this->subir($usuario, $this->archivo(self::imagen('png'), 'a.png'));
        $usuario->refresh();

        $this->assertSame(route('avatar.mostrar', $usuario->avatar_path), $usuario->avatarUrl());

        // Un visitante sin sesión puede verla.
        $this->app['auth']->forgetGuards();
        $respuesta = $this->get($usuario->avatarUrl())->assertOk();

        $respuesta->assertHeader('Content-Type', 'image/webp');
        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
        $respuesta->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $respuesta->assertHeader('Content-Disposition', 'inline');

        $cache = $respuesta->headers->get('Cache-Control');
        foreach (['public', 'max-age=31536000', 'immutable'] as $directiva) {
            $this->assertStringContainsString($directiva, $cache);
        }
        $this->assertStringNotContainsString('no-store', $cache);
        $this->assertStringNotContainsString('private', $cache);

        // SecurityHeaders (middleware global) sigue aplicándose y no contradice lo anterior.
        $respuesta->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
        $this->assertCount(1, $respuesta->headers->all('x-content-type-options'));
        $this->assertCount(1, $respuesta->headers->all('cross-origin-resource-policy'));

        $info = getimagesizefromstring($respuesta->streamedContent());
        $this->assertSame('image/webp', $info['mime']);
    }

    public function test_un_usuario_sin_foto_no_tiene_url(): void
    {
        $this->assertNull(User::factory()->create()->avatarUrl());
    }

    public function test_una_foto_de_nadie_o_ya_reemplazada_da_404(): void
    {
        $usuario = User::factory()->create();
        $this->subir($usuario, $this->archivo(self::imagen('png'), 'a.png'));
        $primera = $usuario->fresh()->avatar_path;

        // Nombre con formato válido pero que no es de nadie (y existe en el disco).
        $huerfana = str_repeat('Z', 40).'.webp';
        Storage::disk('local')->put("avatares/{$huerfana}", 'x');
        $this->get("/avatares/{$huerfana}")->assertNotFound();

        // Foto reemplazada cuyo archivo, por lo que sea, sigue en el disco.
        $copia = Storage::disk('local')->get("avatares/{$primera}");
        $this->subir($usuario, $this->archivo(self::imagen('png'), 'b.png'));
        Storage::disk('local')->put("avatares/{$primera}", $copia);

        $this->get("/avatares/{$primera}")->assertNotFound();

        // Foto vigente en la BD pero sin archivo en el disco.
        Storage::disk('local')->delete("avatares/{$usuario->fresh()->avatar_path}");
        $this->get($usuario->fresh()->avatarUrl())->assertNotFound();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function urlsMaliciosas(): array
    {
        return [
            'recorrido de directorios' => ['/avatares/..%2F..%2F.env'],
            'recorrido sin codificar' => ['/avatares/../../.env'],
            'extensión php' => ['/avatares/abc.php'],
            '39 caracteres' => ['/avatares/'.str_repeat('a', 39).'.webp'],
            '41 caracteres' => ['/avatares/'.str_repeat('a', 41).'.webp'],
            'otra extensión con 40 caracteres' => ['/avatares/'.str_repeat('a', 40).'.php'],
            'caracteres no permitidos' => ['/avatares/'.str_repeat('a', 39).'-.webp'],
        ];
    }

    #[DataProvider('urlsMaliciosas')]
    public function test_las_urls_con_formato_invalido_dan_404(string $url): void
    {
        $this->get($url)->assertNotFound();
    }

    /**
     * El disco local tiene 'serve' => false (config/filesystems.php): Laravel no
     * registra la ruta storage/{path}, así que las fotos solo salen por /avatares.
     */
    public function test_la_ruta_storage_del_disco_local_no_existe(): void
    {
        $usuario = User::factory()->create();
        $this->subir($usuario, $this->archivo(self::imagen('png'), 'a.png'));

        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
        $this->get('/storage/avatares/'.$usuario->fresh()->avatar_path)->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Ajuste 2b: la foto se sirve SIN sesión ni cookies
    // ---------------------------------------------------------------------

    /** Sube una foto para un usuario nuevo y devuelve su URL pública. */
    private function urlDeFotoVigente(): string
    {
        $usuario = User::factory()->create();
        $this->subir($usuario, $this->archivo(self::imagen('png'), 'a.png'));

        return (string) $usuario->fresh()->avatarUrl();
    }

    private function assertSinCookies(TestResponse $respuesta): void
    {
        $this->assertSame([], $respuesta->headers->getCookies());
        $this->assertFalse($respuesta->headers->has('Set-Cookie'));
    }

    /** Las cabeceras que pone SecurityHeaders (middleware global). */
    private function assertCabecerasDeSeguridad(TestResponse $respuesta): void
    {
        $this->assertStringContainsString("default-src 'self'", (string) $respuesta->headers->get('Content-Security-Policy'));
        $respuesta->assertHeader('X-Frame-Options', 'DENY');
        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
        $respuesta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $respuesta->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $respuesta->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $respuesta->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
        $respuesta->assertHeaderMissing('X-Powered-By');
    }

    /**
     * La respuesta lleva Cache-Control: public. Si además llevara Set-Cookie, un proxy
     * o CDN compartido podría guardar la cookie de sesión y entregársela a otra persona.
     */
    public function test_la_foto_no_envia_cookies_ni_a_visitantes_ni_a_usuarios(): void
    {
        $url = $this->urlDeFotoVigente();

        // Con un usuario con sesión iniciada.
        $this->actingAs(User::factory()->create());
        $this->assertSinCookies($this->get($url)->assertOk());

        // Como visitante.
        $this->app['auth']->forgetGuards();
        $this->assertSinCookies($this->get($url)->assertOk());
    }

    /**
     * En las pruebas la sesión usa el driver "array" (phpunit.xml), que no escribe en
     * ninguna tabla. Para que la comprobación tenga sentido, esta prueba cambia al
     * driver "database" (el de producción) y usa GET / como control: esa ruta SÍ
     * debe crear una fila, así se sabe que el driver funciona.
     */
    public function test_pedir_una_foto_no_crea_sesiones_en_la_base_de_datos(): void
    {
        $url = $this->urlDeFotoVigente();
        $this->app['auth']->forgetGuards();

        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->assertSame(0, DB::table('sessions')->count());

        // Control: una página normal sí guarda la sesión.
        $this->get('/')->assertOk();
        $this->assertSame(1, DB::table('sessions')->count());
    }

    public function test_las_cabeceras_de_seguridad_siguen_en_la_foto_y_en_el_404(): void
    {
        $this->assertCabecerasDeSeguridad($this->get($this->urlDeFotoVigente())->assertOk());

        // 404 de la propia ruta (formato válido, pero la foto no es de nadie)...
        $respuesta = $this->get('/avatares/'.str_repeat('Z', 40).'.webp')->assertNotFound();
        $this->assertCabecerasDeSeguridad($respuesta);
        $this->assertSinCookies($respuesta);

        // ...y 404 de una URL que no coincide con la ruta.
        $this->assertCabecerasDeSeguridad($this->get('/avatares/abc.php')->assertNotFound());
    }

    /** Control: quitar la sesión de /avatares no afectó al resto del sitio. */
    public function test_las_demas_rutas_siguen_enviando_la_cookie_de_sesion(): void
    {
        $nombreCookie = config('session.cookie');

        $this->get('/')->assertOk()->assertCookie($nombreCookie)->assertCookie('XSRF-TOKEN');

        $this->actingAs(User::factory()->create())
            ->get(route('perfil.edit'))
            ->assertOk()
            ->assertCookie($nombreCookie)
            ->assertCookie('XSRF-TOKEN');
    }

    // ---------------------------------------------------------------------
    // o: GD no puede procesar la imagen
    // ---------------------------------------------------------------------

    /**
     * PNG con la cabecera y el bloque IHDR correctos (200×200) seguidos de basura:
     * fileinfo dice image/png y getimagesize() lee las medidas, así que pasa el
     * FormRequest; pero GD no puede decodificarlo.
     */
    public function test_si_gd_no_puede_procesar_la_imagen_hay_un_error_amigable(): void
    {
        $usuario = User::factory()->create();
        $corrupto = substr(self::imagen('png', 200, 200), 0, 33).str_repeat("\x00\xFFbasura", 400);

        $this->from(route('perfil.edit'))
            ->subir($usuario, $this->archivo($corrupto, 'rota.png'))
            ->assertRedirect(route('perfil.edit'))
            ->assertSessionHasErrorsIn('avatar', [
                'avatar' => 'No pudimos procesar la imagen. Prueba con otra JPG, PNG o WebP.',
            ]);

        $this->assertSinCambios($usuario);
    }

    public function test_si_gd_falla_al_reemplazar_se_conserva_la_foto_anterior(): void
    {
        $usuario = User::factory()->create();
        $this->subir($usuario, $this->archivo(self::imagen('png'), 'a.png'));
        $anterior = $usuario->fresh()->avatar_path;
        AuditLog::query()->delete();

        $corrupto = substr(self::imagen('png', 200, 200), 0, 33).str_repeat("\x00\xFFbasura", 400);
        $this->subir($usuario, $this->archivo($corrupto, 'rota.png'))->assertSessionHasErrorsIn('avatar', 'avatar');

        $this->assertSinCambios($usuario, $anterior);
    }
}
