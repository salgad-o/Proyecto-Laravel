<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ControllerContact;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ComentarioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NoticiaController;

Route::get('/', [PageController::class, 'home'])->name('home');

Route::get('/about', [PageController::class, 'about'])->name('about');

Route::get('/contact', [PageController::class, 'contact'])->name('contact');

Route::post('/contact', [ControllerContact::class, 'send'])
    ->middleware('throttle:10,1')
    ->name('contact.send');

Route::middleware('guest')->group(function () {
    Route::get('/registro', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/registro', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('register.store');

    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.store');
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

// Moderación de comentarios: solo administradores.
Route::get('/dashboard/comentarios', [ComentarioController::class, 'index'])
    ->middleware(['auth', 'role:admin'])
    ->name('comentarios.index');

Route::get('/app', function() {
    return view('layouts.app');
})->name('app');