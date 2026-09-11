<?php

use App\Http\Controllers\Api\WordAddinController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST para el Add-in de Word (Office.js)
|--------------------------------------------------------------------------
|
| Los endpoints son consumidos por `public/word-addin/index.html` dentro de
| Microsoft Word. `POST /api/addin/login` emite un Bearer token (Sanctum);
| el resto exigen autenticación de tipo "Bearer Token".
|
*/

Route::prefix('addin')->name('addin.')->group(function () {
    Route::post('/login', [WordAddinController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/documents', [WordAddinController::class, 'documents'])->name('documents');
        Route::get('/folders', [WordAddinController::class, 'contents'])->name('contents');
        Route::post('/documents/{document}/lock', [WordAddinController::class, 'lock'])->name('lock');
        Route::post('/documents/{document}/checkout', [WordAddinController::class, 'checkout'])->name('checkout');
        Route::post('/documents/{document}/modify', [WordAddinController::class, 'modify'])->name('modify');
        Route::get('/documents/{document}/session', [WordAddinController::class, 'session'])->name('session');
        Route::get('/documents/{document}/download', [WordAddinController::class, 'download'])->name('download');
        Route::post('/documents/{document}/upload', [WordAddinController::class, 'upload'])->name('upload');
        Route::post('/documents/{document}/unlock', [WordAddinController::class, 'unlock'])->name('unlock');
        Route::post('/documents/{document}/heartbeat', [WordAddinController::class, 'heartbeat'])->name('heartbeat');
        Route::post('/logout', [WordAddinController::class, 'logout'])->name('logout');
        Route::get('/documents/{document}/history', [WordAddinController::class, 'history'])->name('history');
        Route::post('/files/{file}/link', [WordAddinController::class, 'linkFile'])->name('files.link');
    });
});