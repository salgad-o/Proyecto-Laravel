# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**Nexus Plays**: a Laravel 12 (PHP 8.2+) CMS for video game news, built for a university "Desarrollo de Software Seguro" course. Code comments, UI text, route paths (`/registro`), and domain terms are in **Spanish** (`Noticia`, `borrador`/`publicada`, `lector`). Keep new code consistent with that. Security is the point of the project, so authorization and validation changes need extra care.

Runs under XAMPP locally. The local `.env` uses MySQL, while `.env.example` defaults to SQLite.

## Commands

```bash
composer setup            # first-time install: deps, .env, key, migrate, npm build
composer dev              # serve + queue:listen + pail logs + vite, all at once
php artisan serve         # just the app server
npm run dev / npm run build

composer test             # config:clear + php artisan test
php artisan test --filter=SomeTest            # single test class or method
php artisan test tests/Feature/SomeTest.php   # single file

vendor/bin/pint           # code style (Laravel Pint)
php artisan migrate
```

Tests use in-memory SQLite plus array session/mail drivers (`phpunit.xml`). Right now only the default example tests exist.

## Architecture

**Routing** (`routes/web.php`): everything uses web routes, with no API. The `noticias` resource is registered **twice**: `create/store/edit/update/destroy` behind `['auth', 'role:admin,editor']`, then `index/show` as public routes. The protected block has to stay first, otherwise `/noticias/create` gets matched as `/noticias/{noticia}`. The route parameter is renamed to `noticia` via `->parameters()`.

**Authorization is layered:**
1. The `role` middleware alias (`EnsureUserHasRole`, registered in `bootstrap/app.php`) is a coarse route gate: `role:admin,editor`.
2. `NoticiaPolicy` (auto-discovered) holds the fine-grained rules. Admins can do anything. Editors can update or delete only their own noticias. Anyone can view a `publicada` noticia, but a `borrador` is visible only to its author and admins.
3. Controllers call `Gate::authorize(...)` explicitly on every action. FormRequests return `authorize(): true` and handle validation only.

**Roles**: there's a `roles` table (`name`: `admin` | `editor` | `lector`) and a `users.role_id` FK. The roles migration seeds the three roles and defaults `role_id` to `lector`. `role_id` is deliberately **not** in `User::$fillable` to prevent privilege escalation through mass assignment, so it must be set explicitly (for example `$user->role_id = ...; $user->save();`). Use `User::hasRole(...$roles)` and `isAdmin()` for checks. The seeder and `UserFactory` don't assign roles.

**Noticia lifecycle**: `status` is `borrador` or `publicada`. `published_at` is managed in `NoticiaController`, not by the model. It's set to `now()` on first publish and cleared when a noticia goes back to `borrador`. `user_id` is set through `$request->user()->noticias()->make(...)` and isn't fillable. The `noticias` table started out as `posts` and was renamed by a migration.

**Auth**: a hand-rolled `AuthController` (no Breeze/Fortify) handles session regeneration on login and register, invalidates the session on logout, and requires passwords of at least 12 characters with mixed case, numbers, and symbols. Login is throttled at `throttle:5,1` and contact at `throttle:10,1`.

**Views**: Blade with `layouts/app.blade.php` as the shell. Static pages live in `resources/views/pages/` and noticia CRUD lives in `resources/views/noticias/`. Styling is pure CSS in `resources/css/app.css`, built by Vite, with `--np-*` color variables defined in `:root`.

## Conventions

- Branches: `main` is stable, and `nexus-plays` is for development and integration (open PRs into `main`).
- Commits use these prefixes: `feat:`, `fix:`, `security:`, `docs:`, `refactor:`, `style:`, `test:`. Messages are written in Spanish, e.g. `feat: agregar módulo de comentarios`.
- Planned features from the README that aren't built yet: comments CRUD, user and role management, audit log, and password recovery.


## Alcance del proyecto (Nexus Plays)

Revista de videojuegos. Secciones: Inicio, Noticias (lista, detalle, comentarios), Registro, Login, Perfil, Dashboard (Noticias, Usuarios, Roles, Comentarios, Auditoría).

**Dentro del alcance:** registro, login, recuperación de contraseña, dashboard, roles, CRUD de noticias, CRUD de comentarios, perfil de usuario, auditoría básica (quién creó/modificó una noticia), CSRF, validación en servidor, prevención de XSS, hash de contraseñas.

**Fuera del alcance (NO implementar ni sugerir):** chat, likes, sistema de puntuación, mensajería, foro, marketplace, streaming, notificaciones en tiempo real.

**NO usar Breeze, Jetstream ni Fortify.** La autenticación es propia y debe conservarse. La recuperación de contraseña se hará a mano con `Password::sendResetLink()` / `Password::reset()`.

## Orden de fases

1. ✅ Post → Noticia (excerpt, status, published_at)
2. ✅ Roles (admin, editor, lector) + middleware + NoticiaPolicy
3. Auditoría
4. Comentarios
5. Perfil de usuario
6. Recuperación de contraseña
7. Dashboard y rediseño visual

No adelantar fases ni mezclar cambios de varias fases en un mismo commit.

## Diseño visual

- **CSS puro** en `resources/css/app.css`, con las variables de color `--np-*` definidas en `:root`. NO reinstalar Tailwind ni usar sus clases.
- Tema oscuro/morado (ya aplicado en login, registro y navbar).
- Referencia del dashboard: sidebar lateral, buscador superior, botón "+ Nuevo Artículo", tarjetas KPI, lista de noticias con miniaturas y panel lateral derecho.
- Al rediseñar una vista, mantener intacta la lógica Blade (`@csrf`, `old()`, `@error`, rutas con nombre).

## Reglas de seguridad

- Nunca leer, mostrar ni commitear `.env`.
- Nunca relajar una Policy, un middleware o un throttle sin avisar explícitamente y explicar el riesgo.
- Toda entrada de usuario se valida en un FormRequest; toda salida en Blade usa `{{ }}` (nunca `{!! !!}` con datos de usuario).
- Contexto legal: Ley 1581 de 2012 (Habeas Data, Colombia).

## Cómo trabajar conmigo

- Soy estudiante: explica qué hace cada cambio y por qué, en español.
- Trabaja paso a paso, una fase o tarea a la vez, y espera mi confirmación antes de seguir.
- Indica siempre dónde ejecutar cada comando (PowerShell, navegador, etc.).
- Avisa ANTES de cualquier cambio que pueda romper funcionalidad existente (migraciones, rutas, policies).
- Después de cada cambio, dime cómo probarlo (`php artisan test`, ruta en el navegador, etc.).
- Escribe pruebas Feature para lo nuevo: hoy solo existen las de ejemplo. 