# Correo: envío, recepción y tareas programadas (NP-46)

Cómo poner en marcha y mantener el correo de Nexus Plays: el formulario de contacto y la recuperación de contraseña (envío por SMTP, en cola) y la bandeja "Correos recibidos" (recepción por IMAP).

## Procesos necesarios

| Proceso | Comando | Para qué | Si no está en marcha |
|---|---|---|---|
| Web | `php artisan serve` (o Apache de XAMPP) | Sirve la aplicación | No hay sitio |
| Cola | `php artisan queue:work` | Envía los correos de contacto y los enlaces de recuperación de contraseña (jobs cifrados en la tabla `jobs`) | Los correos se quedan en `jobs` y **no salen**, aunque el visitante vea "Recibimos tu mensaje" |
| Programador | `php artisan schedule:work` (desarrollo) o `php artisan schedule:run` cada minuto (producción) | Ejecuta las tareas de `routes/console.php` | No se importan correos ni se limpian los jobs fallidos |

En desarrollo, `composer dev` arranca la web, la cola (`queue:listen`) y Vite. El programador va aparte:

```powershell
php artisan schedule:work
```

### Tareas programadas (`routes/console.php`)

| Tarea | Frecuencia | Motivo |
|---|---|---|
| `mail:receive --limite=50 --dias=2` | Cada 5 minutos, `withoutOverlapping(10)` | Importa el buzón a `received_emails`. Es idempotente (no duplica). El bloqueo caduca a los 10 minutos para que un proceso muerto no pare la recepción un día entero. |
| `queue:prune-failed --hours=168` | Diaria (00:00, hora del servidor) | Borra los jobs fallidos de más de 7 días: guardan datos personales cifrados (deuda 29). |

Ninguna tarea guarda su salida en archivos ni la envía por correo: la salida de `mail:receive` lleva remitentes y asuntos.

Para comprobar lo programado: `php artisan schedule:list`.

## Programarlo en producción

### Windows (Programador de tareas)

1. Abrir **Programador de tareas** → **Crear tarea…** (no "tarea básica": hace falta repetir cada minuto).
2. **General**: nombre `Nexus Plays - schedule:run`; marcar *Ejecutar tanto si el usuario inició sesión como si no*.
3. **Desencadenadores** → **Nuevo**: *Diariamente*, *Repetir la tarea cada* **1 minuto**, *durante* **Indefinidamente**.
4. **Acciones** → **Nuevo**:
   - Programa: `C:\xampp\php\php.exe`
   - Argumentos: `artisan schedule:run`
   - Iniciar en: `C:\xampp\htdocs\Repo`
5. **Configuración**: *Si la tarea ya se está ejecutando*: **No iniciar una nueva instancia**.

El worker de la cola (`php artisan queue:work`) debe estar siempre en marcha. En Windows se puede crear otra tarea "Al iniciar el sistema" con los mismos *Programa* e *Iniciar en* y el argumento `artisan queue:work --tries=3`.

### Linux

Cron del usuario que ejecuta la web (`crontab -e`):

```cron
* * * * * cd /ruta/a/Repo && php artisan schedule:run >> /dev/null 2>&1
```

Worker con Supervisor (`/etc/supervisor/conf.d/nexus-worker.conf`):

```ini
[program:nexus-worker]
command=php /ruta/a/Repo/artisan queue:work --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=120
```

## Variables de entorno

Van en `.env` (nunca en git). Plantilla en `.env.example`, sin valores reales.

| Variable | Uso |
|---|---|
| `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Envío por SMTP (con Gmail: `smtps`, `smtp.gmail.com`, `465`) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Remitente de todos los correos y buzón que recibe los de contacto |
| `IMAP_HOST`, `IMAP_PORT`, `IMAP_ENCRYPTION`, `IMAP_VALIDATE_CERT`, `IMAP_PROTOCOL`, `IMAP_USERNAME`, `IMAP_PASSWORD` | Recepción (con Gmail: `imap.gmail.com`, `993`, `ssl`, `true`, `imap`) |
| `QUEUE_CONNECTION=database` | Obligatorio: con `sync` el envío ocurre dentro de la petición y vuelve la fuga por tiempo en "olvidé mi contraseña" (deuda 5) |
| `APP_URL` | Debe ser la URL pública real: el worker genera el enlace de recuperación fuera de una petición web y lo toma de aquí |

Comprobar sin abrir `.env`:

```powershell
php artisan config:show queue.default
php artisan config:show app.url
```

## Avisos de seguridad

- **Contraseña de aplicación.** `MAIL_PASSWORD` e `IMAP_PASSWORD` deben ser una contraseña de aplicación de Google (requiere verificación en dos pasos), nunca la contraseña de la cuenta. Si se filtra: Cuenta de Google → Seguridad → **Contraseñas de aplicaciones** → eliminarla, crear otra y actualizar `.env`. Después, reiniciar el worker.
- **Nunca activar el debug de IMAP.** `config/imap.php` fija `options.debug = false`: en `true` el paquete imprime todo el protocolo, incluido el `LOGIN` con la contraseña.
- **No rotar `APP_KEY` con jobs pendientes** (deuda 28). Los jobs de `jobs` y `failed_jobs` van cifrados con esa clave: tras rotarla ya no se pueden descifrar. Antes, vaciar la cola con `php artisan queue:work --stop-when-empty` y revisar `failed_jobs`.
- **Reiniciar el worker tras cambiar el código o `.env`.** `queue:work` mantiene el código en memoria: `php artisan queue:restart` (Supervisor o el Programador de tareas lo vuelven a arrancar).
- **`storage/logs` fuera de git.** El log puede contener errores del SMTP o del IMAP con direcciones de correo. Ya está excluido por `storage/logs/.gitignore`: no forzarlo con `git add -f`.
- **El contenido de los correos recibidos no es confiable.** Se guarda como texto plano y se muestra escapado. No convertir el cuerpo en HTML ni en enlaces clicables.
