<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas programadas (NP-46 paso 8)
|--------------------------------------------------------------------------
|
| Necesitan "php artisan schedule:work" (desarrollo) o "php artisan schedule:run"
| cada minuto (producción). Ver docs/correo.md.
|
| Ninguna usa sendOutputTo, appendOutputTo ni emailOutputTo: la salida de
| mail:receive incluye remitentes y asuntos, y no debe quedar en archivos ni
| reenviarse por correo.
|
*/

// Recepción de correo por IMAP cada 5 minutos.
// - --limite=50: 10 correos cada 5 minutos podrían no bastar en un pico.
// - --dias=2: cubre una caída del equipo de hasta un día; los ya guardados no se
//   duplican (mail:receive es idempotente por message_id).
// - withoutOverlapping(10): el bloqueo caduca a los 10 minutos. Por defecto dura
//   24 horas: si el proceso muriera a mitad, la recepción quedaría parada un día.
Schedule::command('mail:receive --limite=50 --dias=2')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Deuda 29: los jobs fallidos (failed_jobs) guardan datos personales, aunque
// cifrados (correo del visitante, mensaje de contacto). Se conservan 7 días para
// poder investigar el fallo y después se borran.
Schedule::command('queue:prune-failed --hours=168')
    ->daily();
