<?php

use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\ComentarioController;
use App\Http\Controllers\ConsentimientoCookiesController;
use App\Http\Controllers\ControllerContact;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\NoticiaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UsuarioController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', [PageController::class, 'home'])->name('home');

Route::get('/about', [PageController::class, 'about'])->name('about');

Route::get('/contact', [PageController::class, 'contact'])->name('contact');

Route::post('/contact', [ControllerContact::class, 'send'])
    ->middleware('throttle:10,1')
    ->name('contact.send');

// Páginas legales (NP-43): públicas para visitantes y usuarios con sesión.
Route::get('/cookies', [LegalController::class, 'cookies'])->name('legal.cookies');
Route::get('/privacidad', [LegalController::class, 'privacidad'])->name('legal.privacidad');
Route::get('/terminos', [LegalController::class, 'terminos'])->name('legal.terminos');

// Banner de cookies (NP-43 paso 2): sin auth, porque los visitantes también eligen.
// Lleva CSRF (grupo web) y throttle para que no se use para inundar el servidor.
Route::post('/cookies/consentimiento', [ConsentimientoCookiesController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('cookies.consentimiento');

Route::middleware('guest')->group(function () {
    Route::get('/registro', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/registro', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('register.store');

    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.store');

    // Recuperación de contraseña: los nombres password.* son los que usa
    // la notificación ResetPassword de Laravel.
    Route::get('/olvide-contrasena', [PasswordResetController::class, 'showRequest'])
        ->name('password.request');
    Route::post('/olvide-contrasena', [PasswordResetController::class, 'send'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/restablecer-contrasena/{token}', [PasswordResetController::class, 'showReset'])
        ->name('password.reset');
    Route::post('/restablecer-contrasena', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

// Auditoría: solo administradores (middleware de rol + AuditLogPolicy en el controlador).
Route::get('/dashboard/auditoria', [AuditoriaController::class, 'index'])
    ->middleware(['auth', 'role:admin'])
    ->name('auditoria.index');

// Noticias: crear, editar y borrar exigen sesión Y rol de administrador o editor.
// IMPORTANTE: va ANTES de las públicas para que /noticias/create
// no se confunda con /noticias/{noticia}.
Route::resource('noticias', NoticiaController::class)
    ->except(['index', 'show'])
    ->parameters(['noticias' => 'noticia'])
    ->middleware(['auth', 'role:admin,editor']);

// Noticias: lista y detalle son públicos (la Policy protege los borradores).
Route::resource('noticias', NoticiaController::class)
    ->only(['index', 'show'])
    ->parameters(['noticias' => 'noticia']);

// Comentarios: exigen sesión; ComentarioPolicy decide quién edita o elimina.
Route::middleware('auth')->group(function () {
    Route::post('/noticias/{noticia}/comentarios', [ComentarioController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('comentarios.store');
    Route::get('/comentarios/{comentario}/editar', [ComentarioController::class, 'edit'])
        ->name('comentarios.edit');
    Route::put('/comentarios/{comentario}', [ComentarioController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('comentarios.update');
    Route::delete('/comentarios/{comentario}', [ComentarioController::class, 'destroy'])
        ->name('comentarios.destroy');
});

// Fotos de perfil (NP-39): públicas, porque los visitantes ven a autores y comentaristas.
// El patrón solo admite el nombre que genera AvatarController (40 letras/números + .webp):
// nada de "/", ".." ni otras extensiones llega al controlador.
//
// SIN sesión ni cookies (ajuste 2b): la respuesta lleva Cache-Control: public, y un
// proxy o CDN compartido podría guardar un Set-Cookie de sesión y entregárselo a otra
// persona (secuestro de sesión). La ruta es pública, es un GET que no cambia nada y
// no usa al usuario, así que no necesita sesión, CSRF ni cookies.
// SecurityHeaders es global (bootstrap/app.php) y se sigue aplicando.
Route::get('/avatares/{archivo}', [AvatarController::class, 'show'])
    ->where('archivo', '[A-Za-z0-9]{40}\.webp')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        // Necesita la sesión (la invalida si la cuenta está desactivada). Aquí no
        // aplica: la foto es pública y la ve igual un visitante que un usuario.
        EnsureUserIsActive::class,
    ])
    ->name('avatar.mostrar');

// Mi perfil (NP-36): cada usuario edita solo su propia cuenta, sin id en la URL.
// Un formulario y una ruta por campo. El correo no se edita aquí (se pide por Contacto).
// Los pasos siguientes agregarán aquí las pestañas Seguridad (contraseña y sesiones),
// Actividad (mis comentarios) y Cuenta (desactivar la cuenta).
Route::middleware('auth')->group(function () {
    Route::get('/perfil', [PerfilController::class, 'edit'])
        ->name('perfil.edit');
    Route::patch('/perfil/nombre', [PerfilController::class, 'updateNombre'])
        ->middleware('throttle:10,1')
        ->name('perfil.nombre');
    Route::patch('/perfil/biografia', [PerfilController::class, 'updateBiografia'])
        ->middleware('throttle:10,1')
        ->name('perfil.biografia');

    // Foto de perfil (NP-39). Procesar una imagen cuesta CPU y memoria: mismo límite que los demás campos.
    Route::post('/perfil/avatar', [AvatarController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('perfil.avatar');
    Route::delete('/perfil/avatar', [AvatarController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('perfil.avatar.quitar');

    // Pestaña Seguridad (NP-36 paso 3). El cambio de contraseña usa el mismo
    // límite que el login: current_password serviría para adivinar contraseñas.
    Route::get('/perfil/seguridad', [PerfilController::class, 'seguridad'])
        ->name('perfil.seguridad');
    Route::put('/perfil/contrasena', [PerfilController::class, 'updateContrasena'])
        ->middleware('throttle:5,1')
        ->name('perfil.contrasena');
    // NP-36 paso 4: también pide la contraseña actual, así que lleva el mismo límite.
    Route::delete('/perfil/sesiones', [PerfilController::class, 'cerrarSesiones'])
        ->middleware('throttle:5,1')
        ->name('perfil.sesiones');

    // Pestaña Actividad (NP-36 paso 5): todo sale del usuario autenticado.
    Route::get('/perfil/actividad', [PerfilController::class, 'actividad'])
        ->name('perfil.actividad');

    // Pestaña Cuenta (NP-36 paso 6). Desactivar pide la contraseña actual: mismo límite que el login.
    Route::get('/perfil/cuenta', [PerfilController::class, 'cuenta'])
        ->name('perfil.cuenta');
    Route::post('/perfil/desactivar', [PerfilController::class, 'desactivar'])
        ->middleware('throttle:5,1')
        ->name('perfil.desactivar');
});

// Moderación de comentarios: solo administradores.
Route::get('/dashboard/comentarios', [ComentarioController::class, 'index'])
    ->middleware(['auth', 'role:admin'])
    ->name('comentarios.index');

// Usuarios: solo administradores (middleware de rol + UserPolicy en el controlador).
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/dashboard/usuarios', [UsuarioController::class, 'index'])
        ->name('usuarios.index');
    Route::patch('/dashboard/usuarios/{usuario}/rol', [UsuarioController::class, 'updateRol'])
        ->name('usuarios.rol');
    Route::patch('/dashboard/usuarios/{usuario}/estado', [UsuarioController::class, 'updateEstado'])
        ->name('usuarios.estado');

    Route::get('/dashboard/roles', [RoleController::class, 'index'])
        ->name('roles.index');
    Route::patch('/dashboard/roles/{role}', [RoleController::class, 'update'])
        ->name('roles.update');
});
