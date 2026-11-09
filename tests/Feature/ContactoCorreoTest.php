<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use App\Models\ContactConsent;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * NP-46 paso 3: el formulario de contacto encola un correo real (solo texto,
 * asunto fijo, visitante en Reply-To) y guarda la constancia de la autorización
 * (deuda 12) sin el texto del mensaje.
 */
class ContactoCorreoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Valores fijos para no depender de .env.
        config([
            'mail.from.address' => 'buzon@nexusplays.test',
            'mail.from.name' => 'Nexus Plays',
            'legal.version' => '0-prueba',
        ]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'name' => 'Ana Lectora',
            'email' => 'ana@nexus.test',
            'message' => 'Hola, me gusta la revista.',
            'acepto_privacidad' => '1',
        ], $cambios);
    }

    private function correoEncolado(): ContactMessage
    {
        $encolados = Mail::queued(ContactMessage::class);
        $this->assertCount(1, $encolados);

        return $encolados->first();
    }

    // ---------- Correo encolado ----------

    public function test_un_envio_valido_encola_el_correo_hacia_el_buzon_con_reply_to_del_visitante(): void
    {
        $this->post(route('contact.send'), $this->datos())
            ->assertRedirect(route('contact'))
            ->assertSessionHasNoErrors();

        Mail::assertNothingSent();
        Mail::assertQueued(ContactMessage::class, function (ContactMessage $correo) {
            return $correo->hasTo('buzon@nexusplays.test')
                && $correo->hasReplyTo('ana@nexus.test', 'Ana Lectora')
                && $correo->hasSubject('Nuevo mensaje de contacto - Nexus Plays');
        });

        $correo = $this->correoEncolado();

        // El From no es el del visitante: el Mailable no define from y usa el global.
        // (No se usa hasFrom(): en Laravel 12 falla si el Envelope no tiene from.)
        $this->assertNull($correo->envelope()->from);
        $this->assertSame([], $correo->from);

        // Solo un destinatario, sin copias.
        $this->assertCount(1, $correo->to);
        $this->assertSame([], $correo->cc);
        $this->assertSame([], $correo->bcc);
    }

    public function test_el_asunto_es_fijo_aunque_el_visitante_escriba_otro(): void
    {
        $this->post(route('contact.send'), $this->datos([
            'name' => 'Asunto: URGENTE',
            'message' => 'Subject: otro asunto',
            'subject' => 'Asunto inventado',
        ]));

        $correo = $this->correoEncolado();

        $this->assertSame('Nuevo mensaje de contacto - Nexus Plays', $correo->envelope()->subject);
        $this->assertFalse($correo->hasSubject('Asunto inventado'));
    }

    // ---------- Constancia ----------

    public function test_se_crea_una_constancia_con_el_message_id_del_correo(): void
    {
        $this->post(route('contact.send'), $this->datos(['email' => '  ANA@Nexus.TEST ']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ContactConsent::count());
        $constancia = ContactConsent::first();

        $this->assertSame('ana@nexus.test', $constancia->email);
        $this->assertSame('0-prueba', $constancia->legal_version);
        $this->assertNotNull($constancia->privacy_accepted_at);
        $this->assertTrue($constancia->privacy_accepted_at->isToday());

        // message_id = uuid@dominio del buzón, y coincide con la cabecera del correo.
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}@nexusplays\.test$/', $constancia->message_id);

        $correo = $this->correoEncolado();
        $this->assertSame($constancia->message_id, $correo->messageId);
        $this->assertSame($constancia->message_id, $correo->headers()->messageId);
        $this->assertSame('ana@nexus.test', $correo->correo);
        $this->assertSame('0-prueba', $correo->legalVersion);
    }

    public function test_la_constancia_no_guarda_nombre_ni_mensaje(): void
    {
        $this->post(route('contact.send'), $this->datos());

        $columnas = array_keys(ContactConsent::first()->getAttributes());
        sort($columnas);

        $this->assertSame(
            ['created_at', 'email', 'id', 'legal_version', 'message_id', 'privacy_accepted_at', 'updated_at'],
            $columnas
        );
    }

    public function test_los_campos_de_la_constancia_enviados_en_el_formulario_se_ignoran(): void
    {
        $this->post(route('contact.send'), $this->datos([
            'legal_version' => '9.9',
            'privacy_accepted_at' => '2020-01-01 00:00:00',
            'message_id' => 'falso@atacante.test',
        ]))->assertSessionHasNoErrors();

        $constancia = ContactConsent::sole();

        $this->assertSame('0-prueba', $constancia->legal_version);
        $this->assertNotSame('falso@atacante.test', $constancia->message_id);
        $this->assertStringEndsWith('@nexusplays.test', $constancia->message_id);
        $this->assertTrue($constancia->privacy_accepted_at->isToday());

        $correo = $this->correoEncolado();
        $this->assertSame('0-prueba', $correo->legalVersion);
        $this->assertSame($constancia->message_id, $correo->messageId);
    }

    public function test_cada_envio_tiene_un_message_id_distinto(): void
    {
        $this->post(route('contact.send'), $this->datos());
        $this->post(route('contact.send'), $this->datos());

        $this->assertSame(2, ContactConsent::count());
        $this->assertSame(2, ContactConsent::distinct()->count('message_id'));
    }

    // ---------- Rechazos: ni constancia ni correo ----------

    public static function envioRechazado(): array
    {
        return [
            'sin casilla' => [['acepto_privacidad' => null], 'acepto_privacidad'],
            'casilla en cero' => [['acepto_privacidad' => '0'], 'acepto_privacidad'],
            'correo inválido' => [['email' => 'no-es-correo'], 'email'],
            'mensaje vacío' => [['message' => ''], 'message'],
            'mensaje demasiado largo' => [['message' => str_repeat('a', 2001)], 'message'],
        ];
    }

    #[DataProvider('envioRechazado')]
    public function test_un_envio_rechazado_no_guarda_constancia_ni_encola_correo(array $cambios, string $campo): void
    {
        $datos = array_filter($this->datos($cambios), fn ($valor) => $valor !== null);

        $this->post(route('contact.send'), $datos)
            ->assertRedirect(route('contact'))
            ->assertSessionHasErrors($campo);

        $this->assertSame(0, ContactConsent::count());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    // ---------- Inyección de cabeceras ----------

    public static function nombresConControl(): array
    {
        return [
            'CRLF con Bcc' => ["Ana\r\nBcc: x@example.com"],
            'solo LF' => ["Ana\nBcc: x@example.com"],
            'solo CR' => ["Ana\rBcc: x@example.com"],
            // En medio del texto: al inicio o al final los quita TrimStrings antes de validar.
            'byte nulo' => ["An\0a"],
            'tabulador' => ["Ana\tLectora"],
            'DEL' => ["An\x7Fa"],
        ];
    }

    #[DataProvider('nombresConControl')]
    public function test_un_nombre_con_caracteres_de_control_se_rechaza(string $nombre): void
    {
        $this->post(route('contact.send'), $this->datos(['name' => $nombre]))
            ->assertRedirect(route('contact'))
            ->assertSessionHasErrors(['name' => 'El nombre no puede tener saltos de línea ni caracteres de control.']);

        $this->assertSame(0, ContactConsent::count());
        Mail::assertNothingQueued();
    }

    public function test_un_nombre_con_tildes_y_enie_se_acepta(): void
    {
        $this->post(route('contact.send'), $this->datos(['name' => 'José Muñoz-Peña']))
            ->assertSessionHasNoErrors();

        Mail::assertQueued(ContactMessage::class, fn (ContactMessage $correo) => $correo->hasReplyTo('ana@nexus.test', 'José Muñoz-Peña'));
    }

    public function test_un_correo_con_salto_de_linea_se_rechaza(): void
    {
        $this->post(route('contact.send'), $this->datos(['email' => "ana@nexus.test\r\nBcc: x@example.com"]))
            ->assertSessionHasErrors('email');

        Mail::assertNothingQueued();
    }

    // ---------- Cuerpo del correo ----------

    public function test_el_cuerpo_de_texto_tiene_los_datos_y_la_linea_de_constancia(): void
    {
        $this->post(route('contact.send'), $this->datos([
            'message' => "Primera línea\nSegunda línea",
        ]));

        $constancia = ContactConsent::sole();
        $correo = $this->correoEncolado();

        $correo->assertSeeInText('Nombre: Ana Lectora');
        $correo->assertSeeInText('Correo: ana@nexus.test');
        $correo->assertSeeInText("Primera línea\nSegunda línea");
        $correo->assertSeeInText(
            'Autorizó el tratamiento de datos el '.$correo->autorizadoEn
            .' (política versión 0-prueba). Ref: '.$constancia->message_id
        );
    }

    public function test_el_texto_del_visitante_llega_literal_sin_entidades_html(): void
    {
        $mensaje = "Tom & Jerry <b>\n<script>alert(1)</script>\n\"comillas\" y 'apóstrofo'";

        $this->post(route('contact.send'), $this->datos([
            'name' => 'Tom & Jerry <b>',
            'message' => $mensaje,
        ]))->assertSessionHasNoErrors();

        $correo = $this->correoEncolado();

        // El Mailable conserva el texto tal cual lo escribió el visitante...
        $this->assertSame($mensaje, $correo->mensaje);

        // ...y el cuerpo text/plain lo muestra literal: el cliente de correo no
        // interpreta HTML en text/plain, y escapar aquí corrompería el dato.
        $correo->assertSeeInText('Nombre: Tom & Jerry <b>');
        $correo->assertSeeInText($mensaje);
        $correo->assertSeeInText('<script>alert(1)</script>');

        foreach (['&amp;', '&lt;', '&gt;', '&quot;', '&#039;'] as $entidad) {
            $correo->assertDontSeeInText($entidad);
        }
    }

    public function test_el_correo_sin_escapar_no_tiene_ninguna_parte_html(): void
    {
        // Imprimir sin escapar solo es seguro porque el correo es exclusivamente
        // text/plain. Si alguien añade una vista HTML, este test debe fallar.
        $this->post(route('contact.send'), $this->datos(['message' => '<script>alert(1)</script>']));

        $correo = $this->correoEncolado();
        $contenido = $correo->content();

        $this->assertSame('emails.contacto', $contenido->text);
        $this->assertNull($contenido->view);
        $this->assertNull($contenido->html);
        $this->assertNull($contenido->markdown);
        $this->assertNull($contenido->htmlString);

        // Tampoco por las propiedades heredadas de Mailable (view(), html(), markdown()).
        $this->assertNull($correo->view);
        $this->assertNull($correo->markdown);
        $this->assertNull($correo->textView);
    }

    // ---------- Correo real (mailer array, cola sync) ----------

    public function test_el_correo_enviado_tiene_el_message_id_de_la_constancia_y_solo_texto(): void
    {
        // Sin fake: la cola sync ejecuta el job y el mailer array guarda el mensaje.
        // setUp() hizo Mail::fake(); el fake guarda el MailManager real en ->manager.
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
        $manager = Mail::getFacadeRoot()->manager;
        Mail::swap($manager);

        $this->post(route('contact.send'), $this->datos([
            'message' => "Hola, me gusta la revista.\nTom & Jerry <script>alert(1)</script>",
        ]))->assertSessionHasNoErrors();

        $enviados = $manager->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $enviados);

        /** @var Email $email */
        $email = $enviados->first()->getOriginalMessage();
        $constancia = ContactConsent::sole();

        $this->assertSame([$constancia->message_id], $email->getHeaders()->get('Message-ID')->getIds());
        $this->assertSame('buzon@nexusplays.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('buzon@nexusplays.test', $email->getTo()[0]->getAddress());
        $this->assertSame('ana@nexus.test', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('Ana Lectora', $email->getReplyTo()[0]->getName());
        $this->assertSame('Nuevo mensaje de contacto - Nexus Plays', $email->getSubject());
        $this->assertNull($email->getHtmlBody());
        $this->assertStringContainsString("Hola, me gusta la revista.\nTom & Jerry <script>alert(1)</script>", $email->getTextBody());
        $this->assertStringNotContainsString('&amp;', $email->getTextBody());
        $this->assertStringNotContainsString('&lt;', $email->getTextBody());
    }

    // ---------- Cola cifrada ----------

    public function test_el_job_en_la_tabla_jobs_no_guarda_los_datos_del_visitante_en_claro(): void
    {
        // Cola real "database" (tabla jobs de la BD de pruebas) y mailer real.
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        Mail::swap(Mail::getFacadeRoot()->manager);

        $nombre = 'Visitante Secreto';
        $correo = 'secreto.visitante@nexus.test';
        $mensaje = 'Texto confidencial del mensaje 7f3a';

        $this->post(route('contact.send'), $this->datos([
            'name' => $nombre,
            'email' => $correo,
            'message' => $mensaje,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('jobs')->count());
        $payload = DB::table('jobs')->value('payload');

        $datosJob = json_decode($payload, true);
        $this->assertSame(SendQueuedMailable::class, $datosJob['data']['commandName']);

        foreach ([$nombre, $correo, $mensaje, 'Visitante', 'secreto', 'confidencial'] as $dato) {
            $this->assertStringNotContainsString($dato, $payload, "El payload contiene «{$dato}» en claro.");
        }

        // El comando va cifrado con APP_KEY; al descifrarlo los datos siguen intactos.
        $comando = $datosJob['data']['command'];
        $this->assertStringStartsNotWith('O:', $comando);
        $descifrado = Crypt::decryptString($comando);
        $this->assertStringContainsString($correo, $descifrado);
        $this->assertStringContainsString($mensaje, $descifrado);
    }

    // ---------- Estructura del Mailable ----------

    public function test_el_mailable_va_en_cola_y_solo_tiene_vista_de_texto(): void
    {
        $correo = new ContactMessage('Ana', 'ana@nexus.test', 'Hola', '2026-11-08T10:00:00+00:00', '0-prueba', 'id@nexusplays.test');

        $this->assertInstanceOf(ShouldQueue::class, $correo);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $correo);
        $this->assertSame(3, $correo->tries);
        $this->assertNotEmpty($correo->backoff);

        $contenido = $correo->content();
        $this->assertSame('emails.contacto', $contenido->text);
        $this->assertNull($contenido->view);
        $this->assertNull($contenido->html);
        $this->assertNull($contenido->markdown);
        $this->assertNull($contenido->htmlString);
    }

    public function test_el_mailable_solo_recibe_escalares(): void
    {
        $constructor = (new \ReflectionClass(ContactMessage::class))->getConstructor();

        foreach ($constructor->getParameters() as $parametro) {
            $this->assertSame('string', (string) $parametro->getType(), $parametro->getName());
        }
    }

    // ---------- Throttle ----------

    public function test_el_envio_numero_11_en_un_minuto_responde_429_sin_constancia_extra(): void
    {
        foreach (range(1, 10) as $intento) {
            $this->post(route('contact.send'), $this->datos())->assertRedirect(route('contact'));
        }

        $this->post(route('contact.send'), $this->datos())->assertStatus(429);

        $this->assertSame(10, ContactConsent::count());
        Mail::assertQueuedCount(10);
    }

    // ---------- Vistas ----------

    public function test_las_vistas_tocadas_no_tienen_codigo_en_linea(): void
    {
        foreach (['pages/contact.blade.php', 'emails/contacto.blade.php'] as $vista) {
            $contenido = file_get_contents(resource_path('views/'.$vista));

            $this->assertStringNotContainsString('style=', $contenido, $vista);
            $this->assertStringNotContainsString('onclick', $contenido, $vista);
            $this->assertStringNotContainsString('<script', $contenido, $vista);
        }
    }

    public function test_ninguna_vista_imprime_sin_escapar_salvo_el_correo_de_texto(): void
    {
        // Única excepción, por ruta exacta: el cuerpo text/plain del correo de contacto.
        $excepcion = 'emails/contacto.blade.php';
        $this->assertFileExists(resource_path('views/'.$excepcion));

        $base = str_replace('\\', '/', resource_path('views')).'/';
        $revisadas = 0;

        $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($archivos as $archivo) {
            $ruta = str_replace('\\', '/', $archivo->getPathname());
            if (! str_ends_with($ruta, '.blade.php')) {
                continue;
            }

            $relativa = substr($ruta, strlen($base));
            if ($relativa === $excepcion) {
                continue;
            }

            $this->assertStringNotContainsString('{!!', file_get_contents($ruta), $relativa);
            $revisadas++;
        }

        // Que el recorrido de verdad revise las vistas (no pase por estar vacío).
        $this->assertGreaterThan(20, $revisadas);
    }

    public function test_la_vista_del_correo_es_texto_plano(): void
    {
        $contenido = file_get_contents(resource_path('views/emails/contacto.blade.php'));

        $this->assertDoesNotMatchRegularExpression('#<[a-z!/]#i', $contenido);
    }
}
