<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ControllerContact;

Route::get('/', [PageController::class, 'home'])->name('home');

Route::get('/about', [PageController::class, 'about'])->name('about');

Route::get('/contact', [PageController::class, 'contact'])->name('contact');

Route::post('/contact', [ControllerContact::class, 'send'])
    ->middleware('throttle:10,1')
    ->name('contact.send');

Route::get('/app', function() {
    return view('layouts.app');
})->name('app');