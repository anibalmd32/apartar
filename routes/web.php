<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
| Cada ruta declara su superficie de tenancy (REQ-ORG-002 RB-4), y la suite de
| REQ-QAX-008 falla con cualquiera que no declare ninguna. La portada es
| marketing y vive fuera del modelo; el escritorio es la primera superficie de
| backoffice y resuelve su organización desde la sesión.
*/

Route::get('/', function () {
    return Inertia::render('welcome');
})->middleware('outside-tenancy')->name('home');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
