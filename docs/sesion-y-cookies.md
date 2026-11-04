# Sesión y cookies (NP-43)

Resumen de qué guarda Nexus Plays en el navegador, cómo está configurada la sesión y qué hay que revisar antes de desplegar.

## Cookies y almacenamiento del sitio

| Nombre | Tipo | Para qué | Atributos | Duración |
|---|---|---|---|---|
| Cookie de sesión (`config('session.cookie')`) | Cookie necesaria | Mantiene la sesión abierta | Cifrada, HttpOnly, SameSite=Lax, Secure en producción | `lifetime` minutos de inactividad (o hasta cerrar el navegador si `expire_on_close` es true) |
| `XSRF-TOKEN` | Cookie necesaria | Token CSRF para peticiones hechas con JavaScript | Cifrada, **no** HttpOnly (a propósito: el JS la lee), SameSite=Lax, Secure en producción | Igual que la sesión |
| `np_consentimiento_cookies` | Cookie necesaria | Guarda la elección del banner (`todas` o `necesarias`) | Cifrada, HttpOnly, SameSite=Lax, Secure según `session.secure` | 6 meses (259200 minutos, `config/legal.php`) |
| `np_barra_plegada` | `localStorage` (preferencia) | Recuerda si la barra lateral está plegada | Solo se lee y escribe con consentimiento `todas`; valor exacto `'1'` | Hasta que se borre o se elija "Solo necesarias" |

Si alguien edita a mano `np_consentimiento_cookies`, no descifra, llega como `null` y el banner vuelve a salir (`app/Support/ConsentimientoCookies.php`).

## Opciones de `config/session.php`

Valores por defecto del archivo y de `.env.example`. El `.env` de cada entorno puede cambiarlos.

| Opción | Valor | Qué hace |
|---|---|---|
| `http_only` | `true` | El JavaScript no puede leer la cookie de sesión: un XSS no puede robarla. |
| `same_site` | `'lax'` | La cookie no viaja en POST desde otros sitios (capa extra contra CSRF). Los enlaces GET desde fuera sí la llevan. |
| `secure` | `true` en producción, `false` en el resto (`SESSION_SECURE_COOKIE`) | Con `true`, las cookies solo viajan por HTTPS. |
| `lifetime` | `120` minutos | Minutos de inactividad antes de que la sesión caduque. |
| `expire_on_close` | `false` | Con `true`, la sesión termina al cerrar el navegador. |
| `driver` | `database` | La sesión se guarda en la tabla `sessions`, que también registra la IP (`ip_address`) y el navegador (`user_agent`). Permite borrar las sesiones de un usuario. |
| `encrypt` | `false` | Si fuera `true`, el contenido de la sesión se cifraría también en la base de datos. La cookie va cifrada de todas formas por `EncryptCookies`. |

## Protecciones de sesión que ya existen

| Protección | Dónde |
|---|---|
| `session()->regenerate()` al iniciar sesión y al registrarse (evita fijación de sesión) | `app/Http/Controllers/AuthController.php` (`login()`, `register()`) |
| `invalidate()` + `regenerateToken()` al cerrar sesión | `app/Http/Controllers/AuthController.php` (`logout()`) |
| Al restablecer la contraseña se borran todas las sesiones del usuario en la tabla `sessions` y se cambia el `remember_token` (NP-4) | `app/Http/Controllers/PasswordResetController.php` (`reset()`) |
| Un usuario desactivado con sesión abierta es expulsado: `logout()`, `invalidate()` y `regenerateToken()` | `app/Http/Middleware/EnsureUserIsActive.php` |
| Login limitado a 5 intentos por minuto (`throttle:5,1`) | `routes/web.php` |

## Antes de desplegar

- [ ] `APP_ENV=production` (y `APP_DEBUG=false`).
- [ ] `SESSION_SECURE_COOKIE=true` en el `.env` de producción.
- [ ] El sitio se sirve solo por HTTPS.
- [ ] Textos legales oficiales en `resources/views/legal/textos/` (cookies, privacidad y términos).
- [ ] En `config/legal.php`: `version` a `'1.0'` y `es_prueba` a `false`.
