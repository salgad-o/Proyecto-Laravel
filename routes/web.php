<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ControllerContact;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ComentarioController;
use App\Http\Controllers\ConsentimientoCookiesController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\NoticiaController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UsuarioController;

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

// Mi perfil (NP-36): cada usuario edita solo su propia cuenta, sin id en la URL.
// Los pasos siguientes agregarán aquí: cambiar contraseña, sesiones abiertas,
// mis comentarios y desactivar la cuenta.
Route::middleware('auth')->group(function () {
    Route::get('/perfil', [PerfilController::class, 'edit'])
        ->name('perfil.edit');
    Route::patch('/perfil', [PerfilController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('perfil.update');
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