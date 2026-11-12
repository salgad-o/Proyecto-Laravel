<?php

namespace Tests\Feature;

use App\Http\Middleware\StartSessionIfCookie;
use App\Models\AuditLog;
use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\User;
use Closure;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NP-47: portada de las noticias.
 *
 * Igual que FotoPerfilTest, se usan UploadedFile REALES (archivos temporales con
 * test = true) y no UploadedFile::fake(): el fake deduce el MIME del NOMBRE, así
 * que un .php llamado "foto.jpg" pasaría mimes/mimetypes aunque en producción
 * fallara. Con archivos reales, fileinfo lee los bytes.
 */
class PortadaNoticiaTest extends TestCase
{
    use RefreshDatabase;

    private const NOMBRE_VALIDO = '/^[A-Za-z0-9]{40}\.webp$/';

    private const CARPETA = 'portadas';

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
    private static function imagen(string $tipo, int $ancho = 500, int $alto = 300, ?Closure $pintar = null): string
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
     */
    private static function jpegConApp1(string $carga, int $ancho = 500, int $alto = 300): string
    {
        $jpeg = self::imagen('jpeg', $ancho, $alto);

        $app1 = "\xFF\xE1".pack('n', strlen($carga) + 2).$carga;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    /** Subida real (fileinfo lee el contenido) con el nombre indicado. */
    private function archivo(string $bytes, string $nombre, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'np47');
        file_put_contents($ruta, $bytes);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, null, $error, true);
    }

    /** @return array<string, string> */
    private function datosNoticia(array $cambios = []): array
    {
        return array_merge([
            'title' => 'Nuevo tráiler de un juego',
            'excerpt' => 'Resumen corto de la noticia',
            'body' => 'Contenido completo de la noticia.',
            'status' => 'publicada',
        ], $cambios);
    }

    /** Crea una noticia con una portada real y devuelve el modelo. */
    private function crearConPortada(
        User $usuario,
        array $extra = [],
        string $tipo = 'png',
        int $ancho = 2000,
        int $alto = 1000,
    ): Noticia {
        $this->actingAs($usuario)
            ->post(route('noticias.store'), array_merge(
                $this->datosNoticia(),
                $extra,
                ['cover_image' => $this->archivo(self::imagen($tipo, $ancho, $alto), 'portada.'.$tipo)],
            ))
            ->assertSessionHasNoErrors();

        return Noticia::latest('id')->firstOrFail();
    }

    /** Crea una noticia con los bytes exactos indicados. */
    private function crearConBytes(User $usuario, string $bytes, string $nombre): Noticia
    {
        $this->actingAs($usuario)
            ->post(route('noticias.store'), array_merge(
                $this->datosNoticia(),
                ['cover_image' => $this->archivo($bytes, $nombre)],
            ))
            ->assertSessionHasNoErrors();

        return Noticia::latest('id')->firstOrFail();
    }

    /**
     * Crea la noticia y su archivo directamente, sin pasar por una petición HTTP.
     * Así no se arranca una sesión antes de probar StartSessionIfCookie.
     */
    private function noticiaConPortadaDirecta(User $autor, string $status = 'publicada'): Noticia
    {
        $noticia = Noticia::factory()->for($autor)->create([
            'status' => $status,
            'published_at' => $status === 'publicada' ? now() : null,
        ]);

        $noticia->cover_image = Str::random(40).'.webp';
        $noticia->save();

        Storage::disk('local')->put(self::CARPETA."/{$noticia->cover_image}", self::imagen('webp'));

        return $noticia;
    }

    /** Contenido del archivo guardado de la noticia. */
    private function guardado(Noticia $noticia): string
    {
        $noticia->refresh();
        $this->assertNotNull($noticia->cover_image);

        return (string) Storage::disk('local')->get(self::CARPETA."/{$noticia->cover_image}");
    }

    private function contarConsultas(Closure $accion): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $accion();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $consultas;
    }

    // ---------------------------------------------------------------------
    // 1. Regresión: /noticias/create ya no da 500
    // ---------------------------------------------------------------------

    public function test_editor_y_admin_pueden_abrir_el_formulario_de_crear(): void
    {
        foreach ([User::factory()->editor()->create(), User::factory()->admin()->create()] as $usuario) {
            $this->actingAs($usuario)->get(route('noticias.create'))->assertOk();
        }
    }

    public function test_el_formulario_de_crear_incluye_los_campos_de_portada(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->get(route('noticias.create'))
            ->assertOk()
            ->assertSee('name="cover_image"', false)
            ->assertDontSee('name="cover_image_alt"', false)
            ->assertDontSee('name="cover_image_caption"', false);
    }

    // ---------------------------------------------------------------------
    // 2. Crear y editar con JPG, PNG y WebP
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function imagenesValidas(): array
    {
        return [
            'JPG' => ['jpeg', 'portada.jpg'],
            'PNG' => ['png', 'portada.png'],
            'WebP' => ['webp', 'portada.webp'],
        ];
    }

    #[DataProvider('imagenesValidas')]
    public function test_crear_guarda_un_webp_dentro_de_1600x900_con_la_proporcion(string $tipo): void
    {
        $editor = User::factory()->editor()->create();

        $noticia = $this->crearConPortada($editor, [], $tipo, 2000, 1000);

        $this->assertMatchesRegularExpression(self::NOMBRE_VALIDO, $noticia->cover_image);
        Storage::disk('local')->assertExists(self::CARPETA."/{$noticia->cover_image}");

        $info = getimagesizefromstring($this->guardado($noticia));
        $this->assertNotFalse($info);
        $this->assertSame('image/webp', $info['mime']);
        $this->assertLessThanOrEqual(1600, $info[0]);
        $this->assertLessThanOrEqual(900, $info[1]);
        $this->assertEqualsWithDelta(2.0, $info[0] / $info[1], 0.01);
    }

    #[DataProvider('imagenesValidas')]
    public function test_editar_guarda_un_webp_dentro_de_1600x900_con_la_proporcion(string $tipo): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->for($editor)->create(['title' => 'Título original']);

        $this->actingAs($editor)
            ->patch(route('noticias.update', $noticia), array_merge(
                $this->datosNoticia(['title' => 'Título con portada']),
                ['cover_image' => $this->archivo(self::imagen($tipo, 2000, 1000), 'nueva.'.$tipo)],
            ))
            ->assertSessionHasNoErrors();

        $noticia->refresh();
        $this->assertMatchesRegularExpression(self::NOMBRE_VALIDO, $noticia->cover_image);
        Storage::disk('local')->assertExists(self::CARPETA."/{$noticia->cover_image}");

        $info = getimagesizefromstring($this->guardado($noticia));
        $this->assertNotFalse($info);
        $this->assertSame('image/webp', $info['mime']);
        $this->assertLessThanOrEqual(1600, $info[0]);
        $this->assertLessThanOrEqual(900, $info[1]);
        $this->assertEqualsWithDelta(2.0, $info[0] / $info[1], 0.01);
    }

    public function test_una_imagen_pequena_no_se_amplia(): void
    {
        $editor = User::factory()->editor()->create();

        $noticia = $this->crearConPortada($editor, [], 'png', 500, 300);

        $info = getimagesizefromstring($this->guardado($noticia));
        $this->assertSame([500, 300], [$info[0], $info[1]]);
    }

    public function test_una_imagen_vertical_cabe_en_900_de_alto(): void
    {
        $editor = User::factory()->editor()->create();

        $noticia = $this->crearConPortada($editor, [], 'png', 600, 1200);

        $info = getimagesizefromstring($this->guardado($noticia));
        $this->assertSame([450, 900], [$info[0], $info[1]]);
    }

    public function test_dos_noticias_con_portada_no_se_pisan(): void
    {
        $editor = User::factory()->editor()->create();

        $primera = $this->crearConPortada($editor);
        $segunda = $this->crearConPortada($editor);

        $this->assertNotSame($primera->cover_image, $segunda->cover_image);
        Storage::disk('local')->assertExists(self::CARPETA."/{$primera->cover_image}");
        Storage::disk('local')->assertExists(self::CARPETA."/{$segunda->cover_image}");
    }

    // ---------------------------------------------------------------------
    // 3. Archivos rechazados sin dejar rastro
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: Closure(): string, 1: string}>
     */
    public static function archivosRechazados(): array
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="300"><script>alert(1)</script></svg>';

        return [
            'texto plano' => [fn () => 'Hola, esto no es una imagen.', 'nota.txt'],
            'PDF' => [fn () => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF", 'documento.pdf'],
            'SVG con script' => [fn () => $svg, 'dibujo.svg'],
            'SVG renombrado a .png' => [fn () => $svg, 'dibujo.png'],
            'PHP renombrado a .jpg' => [fn () => "<?php system('id'); ?>", 'foto.jpg'],
            'GIF' => [function () {
                $gd = imagecreatetruecolor(500, 300);
                ob_start();
                imagegif($gd);

                return (string) ob_get_clean();
            }, 'animacion.gif'],
            'más de 2 MB' => [fn () => self::imagen('png', 500, 300).str_repeat("\0", 2 * 1024 * 1024 + 1024), 'grande.png'],
            'menos de 400×200' => [fn () => self::imagen('png', 399, 199), 'pequena.png'],
            'más de 4000 de ancho' => [fn () => self::imagen('png', 4001, 200), 'ancha.png'],
            'más de 4000 de alto' => [fn () => self::imagen('png', 400, 4001), 'alta.png'],
        ];
    }

    /**
     * @param  Closure(): string  $bytes
     */
    #[DataProvider('archivosRechazados')]
    public function test_un_archivo_no_permitido_se_rechaza_sin_dejar_archivo(Closure $bytes, string $nombre): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->from(route('noticias.create'))
            ->post(route('noticias.store'), array_merge(
                $this->datosNoticia(),
                ['cover_image' => $this->archivo($bytes(), $nombre)],
            ))
            ->assertRedirect(route('noticias.create'))
            ->assertSessionHasErrors('cover_image');

        $this->assertDatabaseCount('noticias', 0);
        $this->assertSame([], Storage::disk('local')->files(self::CARPETA));
    }

    public function test_los_mensajes_de_portada_son_en_espanol(): void
    {
        $editor = User::factory()->editor()->create();

        $casos = [
            [$this->archivo('texto', 'nota.txt'), 'La portada debe ser una imagen JPG, PNG o WebP.'],
            [$this->archivo(self::imagen('png', 399, 199), 'p.png'), 'La portada debe medir entre 400 y 4000 píxeles de ancho y entre 200 y 4000 de alto.'],
            [$this->archivo(self::imagen('png', 500, 300).str_repeat("\0", 2 * 1024 * 1024 + 1024), 'g.png'), 'La portada debe pesar como máximo 2 MB.'],
            [$this->archivo(self::imagen('png', 500, 300), 'f.png', UPLOAD_ERR_INI_SIZE), 'No pudimos recibir la portada. Debe pesar como máximo 2 MB.'],
        ];

        foreach ($casos as [$archivo, $mensaje]) {
            $this->actingAs($editor)
                ->from(route('noticias.create'))
                ->post(route('noticias.store'), array_merge($this->datosNoticia(), ['cover_image' => $archivo]));

            $this->assertContains($mensaje, session('errors')->get('cover_image'));
        }

        $this->assertDatabaseCount('noticias', 0);
    }

    // ---------------------------------------------------------------------
    // 4. La recodificación elimina metadatos y código escondido
    // ---------------------------------------------------------------------

    public function test_un_poliglota_png_php_se_acepta_pero_sin_el_codigo(): void
    {
        $editor = User::factory()->editor()->create();
        $poliglota = self::imagen('png')."<?php echo 'pwned'; ?>";

        $noticia = $this->crearConBytes($editor, $poliglota, 'poliglota.png');

        $guardado = $this->guardado($noticia);
        $this->assertStringNotContainsString('<?php', $guardado);
        $this->assertStringNotContainsString('pwned', $guardado);
    }

    public function test_los_metadatos_exif_y_gps_se_eliminan(): void
    {
        $editor = User::factory()->editor()->create();
        $jpeg = self::jpegConApp1("Exif\0\0GPS-PRUEBA-NP47");

        $this->assertStringContainsString('GPS-PRUEBA-NP47', $jpeg);

        $noticia = $this->crearConBytes($editor, $jpeg, 'con-gps.jpg');

        $guardado = $this->guardado($noticia);
        $this->assertStringNotContainsString('Exif', $guardado);
        $this->assertStringNotContainsString('GPS-PRUEBA-NP47', $guardado);
    }

    // ---------------------------------------------------------------------
    // 5. Reemplazar, quitar, eliminar y fallo de GD
    // ---------------------------------------------------------------------

    public function test_reemplazar_la_portada_borra_el_archivo_anterior(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);
        $primera = $noticia->cover_image;

        $this->actingAs($editor)
            ->patch(route('noticias.update', $noticia), array_merge(
                $this->datosNoticia(),
                ['cover_image' => $this->archivo(self::imagen('jpeg'), 'b.jpg')],
            ))
            ->assertSessionHasNoErrors();

        $noticia->refresh();
        $segunda = $noticia->cover_image;

        $this->assertNotSame($primera, $segunda);
        Storage::disk('local')->assertMissing(self::CARPETA."/{$primera}");
        Storage::disk('local')->assertExists(self::CARPETA."/{$segunda}");
    }

    public function test_quitar_la_portada_borra_el_archivo(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);
        $portada = $noticia->cover_image;

        $this->actingAs($editor)
            ->patch(route('noticias.update', $noticia), array_merge(
                $this->datosNoticia(),
                ['quitar_portada' => '1'],
            ))
            ->assertSessionHasNoErrors();

        $noticia->refresh();
        $this->assertNull($noticia->cover_image);
        Storage::disk('local')->assertMissing(self::CARPETA."/{$portada}");
    }

    public function test_eliminar_la_noticia_borra_su_portada(): void
    {
        $admin = User::factory()->admin()->create();
        $noticia = $this->crearConPortada($admin);
        $portada = $noticia->cover_image;

        $this->actingAs($admin)
            ->delete(route('noticias.destroy', $noticia))
            ->assertRedirect(route('dashboard.noticias.index'));

        Storage::disk('local')->assertMissing(self::CARPETA."/{$portada}");
    }

    public function test_si_gd_falla_al_reemplazar_se_conserva_la_portada_anterior(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);
        $anterior = $noticia->cover_image;
        $auditoriaAntes = AuditLog::where('action', 'cambiar_portada')->count();

        // PNG con cabecera e IHDR válidos (500×300) seguidos de basura: pasa las
        // dimensiones del FormRequest, pero GD no puede decodificarlo.
        $corrupto = substr(self::imagen('png', 500, 300), 0, 33).str_repeat("\x00\xFFbasura", 400);

        $this->actingAs($editor)
            ->from(route('noticias.edit', $noticia))
            ->patch(route('noticias.update', $noticia), array_merge(
                $this->datosNoticia(),
                ['cover_image' => $this->archivo($corrupto, 'rota.png')],
            ))
            ->assertRedirect(route('noticias.edit', $noticia))
            ->assertSessionHasErrors('cover_image');

        $noticia->refresh();
        $this->assertSame($anterior, $noticia->cover_image);
        Storage::disk('local')->assertExists(self::CARPETA."/{$anterior}");
        $this->assertSame($auditoriaAntes, AuditLog::where('action', 'cambiar_portada')->count());
        $this->assertSame([self::CARPETA."/{$anterior}"], Storage::disk('local')->files(self::CARPETA));
    }

    // ---------------------------------------------------------------------
    // 6. Campos sensibles y $fillable
    // ---------------------------------------------------------------------

    public function test_los_campos_sensibles_del_formulario_se_ignoran(): void
    {
        $editor = User::factory()->editor()->create();
        $otro = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->post(route('noticias.store'), array_merge(
                $this->datosNoticia(['status' => 'publicada']),
                [
                    'user_id' => $otro->id,
                    'published_at' => '2000-01-01 00:00:00',
                    'cover_image' => $this->archivo(self::imagen('png'), '../../evil.php.jpg'),
                ],
            ))
            ->assertSessionHasNoErrors();

        $noticia = Noticia::latest('id')->firstOrFail();

        $this->assertSame($editor->id, $noticia->user_id);
        $this->assertMatchesRegularExpression(self::NOMBRE_VALIDO, $noticia->cover_image);
        $this->assertStringNotContainsString('evil', $noticia->cover_image);
        $this->assertNotSame('2000-01-01 00:00:00', $noticia->published_at->format('Y-m-d H:i:s'));
        $this->assertTrue($noticia->published_at->greaterThan(now()->subMinute()));
    }

    public function test_editar_ignora_user_id_y_published_at_enviados(): void
    {
        $editor = User::factory()->editor()->create();
        $otro = User::factory()->editor()->create();
        $noticia = Noticia::factory()->for($editor)->create([
            'title' => 'Original',
            'published_at' => now()->subDay(),
        ]);
        $fecha = $noticia->published_at->format('Y-m-d H:i:s');

        $this->actingAs($editor)
            ->patch(route('noticias.update', $noticia), array_merge(
                $this->datosNoticia(['title' => 'Cambiada']),
                [
                    'user_id' => $otro->id,
                    'published_at' => '2000-01-01 00:00:00',
                    'cover_image' => $this->archivo(self::imagen('png'), 'nueva.png'),
                ],
            ))
            ->assertSessionHasNoErrors();

        $noticia->refresh();
        $this->assertSame($editor->id, $noticia->user_id);
        $this->assertSame($fecha, $noticia->published_at->format('Y-m-d H:i:s'));
        $this->assertMatchesRegularExpression(self::NOMBRE_VALIDO, $noticia->cover_image);
    }

    public function test_el_modelo_solo_permite_asignacion_masiva_de_los_campos_de_texto(): void
    {
        $this->assertSame(
            ['title', 'excerpt', 'body', 'status'],
            (new Noticia)->getFillable(),
        );
    }

    public function test_la_tabla_solo_tiene_la_columna_cover_image(): void
    {
        $this->assertTrue(Schema::hasColumn('noticias', 'cover_image'));
        $this->assertFalse(Schema::hasColumn('noticias', 'cover_image_alt'));
        $this->assertFalse(Schema::hasColumn('noticias', 'cover_image_caption'));
    }

    public function test_no_se_puede_inyectar_una_ruta_de_portada_como_texto(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)
            ->from(route('noticias.create'))
            ->post(route('noticias.store'), array_merge(
                $this->datosNoticia(),
                ['cover_image' => 'portadas/falsa.webp'],
            ))
            ->assertRedirect(route('noticias.create'))
            ->assertSessionHasErrors('cover_image');

        $this->assertDatabaseCount('noticias', 0);
        $this->assertSame([], Storage::disk('local')->files(self::CARPETA));
    }

    // ---------------------------------------------------------------------
    // 7. Servir la portada
    // ---------------------------------------------------------------------

    public function test_una_portada_publicada_se_sirve_con_cabeceras_seguras_y_sin_cookie(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);

        $this->app['auth']->forgetGuards();
        $respuesta = $this->get(route('portadas.mostrar', $noticia->cover_image))->assertOk();

        $respuesta->assertHeader('Content-Type', 'image/webp');
        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
        $respuesta->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $respuesta->assertHeader('Content-Disposition', 'inline');

        $cache = (string) $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=31536000', $cache);
        $this->assertStringContainsString('immutable', $cache);
        $this->assertStringNotContainsString('private', $cache);
        $this->assertStringNotContainsString('no-store', $cache);

        $this->assertSame([], $respuesta->headers->getCookies());
        $this->assertFalse($respuesta->headers->has('Set-Cookie'));

        $info = getimagesizefromstring($respuesta->streamedContent());
        $this->assertSame('image/webp', $info['mime']);
    }

    public function test_una_portada_de_borrador_no_se_cachea_en_compartido(): void
    {
        $autor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($autor, ['status' => 'borrador']);

        $respuesta = $this->actingAs($autor)
            ->get(route('portadas.mostrar', $noticia->cover_image))
            ->assertOk();

        $cache = (string) $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringNotContainsString('max-age', $cache);
    }

    public function test_un_borrador_solo_lo_ven_su_autor_y_un_admin(): void
    {
        $autor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();
        $lector = User::factory()->lector()->create();
        $otroEditor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($autor, ['status' => 'borrador']);
        $url = route('portadas.mostrar', $noticia->cover_image);

        $this->actingAs($autor)->get($url)->assertOk();
        $this->actingAs($admin)->get($url)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertNotFound();

        $this->actingAs($lector)->get($url)->assertNotFound();
        $this->actingAs($otroEditor)->get($url)->assertNotFound();
    }

    /**
     * Camino real: la cookie de sesión del autor, que StartSessionIfCookie debe leer
     * para aplicar la Policy, sin volver a fijarla ni cambiar el ID de sesión.
     */
    public function test_la_cookie_de_sesion_real_del_autor_permite_ver_su_borrador_sin_cambiar_el_id(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app['auth']->forgetGuards();

        $autor = User::factory()->editor()->create(['email' => 'autor-portada@nexus.test']);
        $noticia = $this->noticiaConPortadaDirecta($autor, 'borrador');
        $url = route('portadas.mostrar', $noticia->cover_image);

        $this->post(route('login.store'), [
            'email' => 'autor-portada@nexus.test',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $id = $this->app['session']->getId();
        $this->assertNotSame('', $id);
        $this->assertSame(1, DB::table('sessions')->where('id', $id)->count());

        // La cookie se envía cifrada, como la enviaría el navegador.
        $this->app['auth']->forgetGuards();
        $respuesta = $this->withCookie(config('session.cookie'), $id)->get($url)->assertOk();

        $this->assertSame($id, $this->app['session']->getId());
        $this->assertSame(1, DB::table('sessions')->where('id', $id)->count());
        $this->assertSame([], $respuesta->headers->getCookies());
        $this->assertFalse($respuesta->headers->has('Set-Cookie'));
    }

    public function test_un_visitante_sin_cookie_no_crea_sesion_ni_recibe_set_cookie(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app['auth']->forgetGuards();

        $editor = User::factory()->editor()->create();
        $noticia = $this->noticiaConPortadaDirecta($editor);

        $antes = DB::table('sessions')->count();
        $respuesta = $this->get(route('portadas.mostrar', $noticia->cover_image))->assertOk();

        // Sin cookie no se arranca StartSession: no hay fila nueva ni Set-Cookie.
        $this->assertSame($antes, DB::table('sessions')->count());
        $this->assertSame([], $respuesta->headers->getCookies());
        $this->assertFalse($respuesta->headers->has('Set-Cookie'));
    }

    public function test_el_middleware_de_sesion_condicional_solo_se_aplica_a_portadas(): void
    {
        $portada = Route::getRoutes()->getByName('portadas.mostrar');
        $this->assertNotNull($portada);
        $this->assertContains(StartSessionIfCookie::class, $portada->gatherMiddleware());

        foreach (['home', 'noticias.show', 'avatar.mostrar'] as $nombre) {
            $ruta = Route::getRoutes()->getByName($nombre);
            $this->assertNotNull($ruta, $nombre);
            $this->assertNotContains(StartSessionIfCookie::class, $ruta->gatherMiddleware(), $nombre);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function urlsMaliciosas(): array
    {
        return [
            'recorrido de directorios codificado' => ['/portadas/..%2F..%2F.env'],
            'recorrido sin codificar' => ['/portadas/../../.env'],
            'extensión php' => ['/portadas/abc.php'],
            '39 caracteres' => ['/portadas/'.str_repeat('a', 39).'.webp'],
            '41 caracteres' => ['/portadas/'.str_repeat('a', 41).'.webp'],
            'otra extensión con 40 caracteres' => ['/portadas/'.str_repeat('a', 40).'.php'],
            'caracteres no permitidos' => ['/portadas/'.str_repeat('a', 39).'-.webp'],
        ];
    }

    #[DataProvider('urlsMaliciosas')]
    public function test_las_urls_con_formato_invalido_dan_404(string $url): void
    {
        $this->get($url)->assertNotFound();
    }

    public function test_una_portada_inexistente_o_sin_archivo_da_404(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);

        $this->get(route('portadas.mostrar', str_repeat('Z', 40).'.webp'))->assertNotFound();

        Storage::disk('local')->delete(self::CARPETA."/{$noticia->cover_image}");
        $this->get(route('portadas.mostrar', $noticia->cover_image))->assertNotFound();
    }

    public function test_una_portada_reemplazada_que_sigue_en_el_disco_da_404(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);
        $primera = $noticia->cover_image;
        $copia = Storage::disk('local')->get(self::CARPETA."/{$primera}");

        $this->actingAs($editor)
            ->patch(route('noticias.update', $noticia), array_merge(
                $this->datosNoticia(),
                ['cover_image' => $this->archivo(self::imagen('jpeg'), 'b.jpg')],
            ))
            ->assertSessionHasNoErrors();

        // Simula que el archivo viejo no se pudo borrar del disco.
        Storage::disk('local')->put(self::CARPETA."/{$primera}", $copia);

        $this->get(route('portadas.mostrar', $primera))->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // 8. La portada se muestra DENTRO de los cuatro bloques
    // ---------------------------------------------------------------------

    public function test_los_cuatro_bloques_muestran_la_portada_dentro_del_bloque(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor, ['title' => 'Alfa con portada']);
        $this->crearConPortada($editor, ['title' => 'Beta con portada']);

        $home = $this->get(route('home'))->assertOk()->getContent();
        $this->assertBloqueConImagen($home, 'destacada-bloque', false);

        $lista = $this->get(route('noticias.index'))->assertOk()->getContent();
        $this->assertBloqueConImagen($lista, 'tarjeta-bloque', true);

        $detalle = $this->get(route('noticias.show', $noticia))->assertOk()->getContent();
        $this->assertBloqueConImagen($detalle, 'detalle-bloque', false);
        $this->assertBloqueConImagen($detalle, 'mini-noticia-bloque', true);

        // La imagen ya no va en un bloque aparte debajo del título.
        $this->assertStringNotContainsString('detalle-portada', $detalle);
        $this->assertStringNotContainsString('portada-caption', $detalle);
    }

    public function test_sin_portada_los_bloques_muestran_la_letra_y_ninguna_imagen(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = Noticia::factory()->for($editor)->create(['title' => 'Zeta sin portada']);
        Noticia::factory()->for($editor)->create(['title' => 'Otra sin portada']);

        $home = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('bloque-portada', $home);
        $this->assertBloqueConLetra($home, 'destacada-bloque');

        $lista = $this->get(route('noticias.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('bloque-portada', $lista);
        $this->assertBloqueConLetra($lista, 'tarjeta-bloque');

        $detalle = $this->get(route('noticias.show', $noticia))->assertOk()->getContent();
        $this->assertStringNotContainsString('bloque-portada', $detalle);
        $this->assertBloqueConLetra($detalle, 'detalle-bloque');
        $this->assertBloqueConLetra($detalle, 'mini-noticia-bloque');
    }

    /**
     * Comprueba que el bloque contiene una imagen con la URL de /portadas, alt vacío
     * y, solo donde corresponde, loading="lazy".
     */
    private function assertBloqueConImagen(string $html, string $clase, bool $lazy): void
    {
        $patron = '/<(a|div)[^>]*class="'.preg_quote($clase, '/').'"[^>]*>.*?<\/\1>/s';

        $this->assertMatchesRegularExpression($patron, $html, "No se encontró el bloque {$clase}");
        preg_match($patron, $html, $coincidencia);

        $bloque = $coincidencia[0];
        $this->assertStringContainsString('class="bloque-portada"', $bloque);
        $this->assertStringContainsString('alt=""', $bloque);
        $this->assertStringContainsString('/portadas/', $bloque);

        if ($lazy) {
            $this->assertStringContainsString('loading="lazy"', $bloque, $clase);
        } else {
            $this->assertStringNotContainsString('loading="lazy"', $bloque, $clase);
        }
    }

    /** Comprueba que el bloque, sin portada, sigue mostrando la inicial del título. */
    private function assertBloqueConLetra(string $html, string $clase): void
    {
        $patron = '/<(a|div)[^>]*class="'.preg_quote($clase, '/').'"[^>]*>\s*[A-ZÁÉÍÓÚÑ]\s*<\/\1>/su';

        $this->assertMatchesRegularExpression($patron, $html, "No se encontró la letra en {$clase}");
    }

    public function test_el_formulario_de_editar_muestra_la_portada_actual_y_la_casilla_para_quitarla(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);

        $this->actingAs($editor)
            ->get(route('noticias.edit', $noticia))
            ->assertOk()
            ->assertSee('Portada actual', false)
            ->assertSee('name="quitar_portada"', false)
            ->assertSee('Quitar portada');
    }

    // ---------------------------------------------------------------------
    // 9. Auditoría
    // ---------------------------------------------------------------------

    public function test_cambiar_portada_se_audita_sin_el_nombre_del_archivo(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);

        $registro = AuditLog::where('action', 'cambiar_portada')->firstOrFail();
        $this->assertSame(['portada' => 'sin portada'], $registro->old_values);
        $this->assertSame(['portada' => 'con portada'], $registro->new_values);

        $valores = (string) json_encode([$registro->old_values, $registro->new_values]);
        $this->assertStringNotContainsString($noticia->cover_image, $valores);

        $crear = AuditLog::where('action', 'crear')->firstOrFail();
        $this->assertArrayNotHasKey('cover_image', $crear->new_values);
        $this->assertStringNotContainsString($noticia->cover_image, (string) json_encode($crear->new_values));
    }

    public function test_editar_no_guarda_la_ruta_de_la_portada_en_la_auditoria(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);
        $primera = $noticia->cover_image;

        $this->actingAs($editor)
            ->patch(route('noticias.update', $noticia), array_merge(
                $this->datosNoticia(['title' => 'Título corregido']),
                ['cover_image' => $this->archivo(self::imagen('jpeg'), 'b.jpg')],
            ))
            ->assertSessionHasNoErrors();

        $noticia->refresh();
        $registros = AuditLog::where('action', 'cambiar_portada')->orderBy('id')->get();
        $this->assertCount(2, $registros);

        $ultimo = $registros->last();
        $this->assertSame(['portada' => 'con portada'], $ultimo->old_values);
        $this->assertSame(['portada' => 'con portada'], $ultimo->new_values);

        foreach ($registros as $registro) {
            $valores = (string) json_encode([$registro->old_values, $registro->new_values]);
            $this->assertStringNotContainsString($primera, $valores);
            $this->assertStringNotContainsString($noticia->cover_image, $valores);
        }

        $editar = AuditLog::where('action', 'editar')->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('cover_image', $editar->new_values);
        $this->assertArrayNotHasKey('cover_image', $editar->old_values ?? []);
        $this->assertStringNotContainsString($noticia->cover_image, (string) json_encode($editar->new_values));
    }

    public function test_quitar_la_portada_se_audita_como_con_portada_a_sin_portada(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);

        $this->actingAs($editor)
            ->patch(route('noticias.update', $noticia), array_merge(
                $this->datosNoticia(),
                ['quitar_portada' => '1'],
            ))
            ->assertSessionHasNoErrors();

        $registro = AuditLog::where('action', 'cambiar_portada')->latest('id')->firstOrFail();
        $this->assertSame(['portada' => 'con portada'], $registro->old_values);
        $this->assertSame(['portada' => 'sin portada'], $registro->new_values);
        $this->assertSame('baja', (new AuditLog(['action' => 'cambiar_portada']))->importancia());
    }

    // ---------------------------------------------------------------------
    // 10. Vistas: sin código en línea y con clases existentes
    // ---------------------------------------------------------------------

    public function test_las_vistas_de_portada_no_tienen_codigo_en_linea_y_sus_clases_existen(): void
    {
        $vistas = [
            resource_path('views/noticias/create.blade.php'),
            resource_path('views/noticias/edit.blade.php'),
            resource_path('views/noticias/show.blade.php'),
            resource_path('views/noticias/_tarjeta.blade.php'),
            resource_path('views/pages/home.blade.php'),
        ];
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $clases = [];

        foreach ($vistas as $ruta) {
            $contenido = (string) file_get_contents($ruta);

            $this->assertStringNotContainsString('<script', $contenido, $ruta);
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $contenido, $ruta);
            $this->assertDoesNotMatchRegularExpression('/\son(click|load|error|change|submit)\s*=/i', $contenido, $ruta);

            preg_match_all('/class="([^"]*)"/', $contenido, $coincidencias);

            foreach ($coincidencias[1] as $valor) {
                if (str_contains($valor, '{{') || str_contains($valor, '@')) {
                    continue;
                }

                foreach (preg_split('/\s+/', trim($valor)) as $clase) {
                    if ($clase !== '') {
                        $clases[$clase] = true;
                    }
                }
            }
        }

        foreach (array_keys($clases) as $clase) {
            $this->assertStringContainsString('.'.$clase, $css, "Falta la clase .{$clase} en app.css");
        }
    }

    // ---------------------------------------------------------------------
    // 11. Sin consultas N+1
    // ---------------------------------------------------------------------

    public function test_la_lista_no_tiene_consultas_n_1(): void
    {
        $editor = User::factory()->editor()->create();
        Noticia::factory()->for($editor)->create();

        $conUna = $this->contarConsultas(fn () => $this->get(route('noticias.index'))->assertOk());

        Noticia::factory()->count(5)->for($editor)->create();

        $conVarias = $this->contarConsultas(fn () => $this->get(route('noticias.index'))->assertOk());

        $this->assertSame($conUna, $conVarias);
    }

    public function test_el_detalle_no_tiene_consultas_n_1(): void
    {
        $noticia = Noticia::factory()->create();
        Comentario::factory()->create(['noticia_id' => $noticia->id]);

        $conUno = $this->contarConsultas(fn () => $this->get(route('noticias.show', $noticia))->assertOk());

        Comentario::factory()->count(4)->create(['noticia_id' => $noticia->id]);

        $conVarios = $this->contarConsultas(fn () => $this->get(route('noticias.show', $noticia))->assertOk());

        $this->assertSame($conUno, $conVarios);
    }

    public function test_servir_una_portada_no_hace_consultas_por_elemento(): void
    {
        $editor = User::factory()->editor()->create();
        $noticia = $this->crearConPortada($editor);

        $consultas = $this->contarConsultas(
            fn () => $this->get(route('portadas.mostrar', $noticia->cover_image))->assertOk(),
        );

        $this->assertLessThanOrEqual(2, $consultas);
    }
}
