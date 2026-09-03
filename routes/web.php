<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\DocumentController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', [FolderController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/explorer', [FolderController::class, 'index'])->name('explorer');

    Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');
    Route::post('/folders/{folder}/restore', [FolderController::class, 'restore'])->name('folders.restore');
    Route::delete('/folders/{folder}/force', [FolderController::class, 'forceDestroy'])->name('folders.force-destroy');

    Route::post('/files', [FileController::class, 'store'])->name('files.store');
    Route::get('/files/{file}/download', [FileController::class, 'download'])->name('files.download');
    Route::get('/files/{file}/blob', [FileController::class, 'blob'])->name('files.blob');
    Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');
    Route::post('/files/{file}/restore', [FileController::class, 'restore'])->name('files.restore');
    Route::delete('/files/{file}/force', [FileController::class, 'forceDestroy'])->name('files.force-destroy');

    Route::post('/documents', [DocumentController::class, 'create'])->name('documents.store');
    Route::post('/documents/import-word', [DocumentController::class, 'importWord'])->name('documents.import-word');
    Route::get('/documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
    Route::patch('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::post('/documents/{document}/sync', [DocumentController::class, 'sync'])->name('documents.sync');
    Route::get('/documents/{document}/export-pdf', [DocumentController::class, 'exportPdf'])->name('documents.export-pdf');
    Route::get('/documents/{document}/export-docx', [DocumentController::class, 'exportDocx'])->name('documents.export-docx');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('/documents/{document}/restore', [DocumentController::class, 'restore'])->name('documents.restore');
    Route::delete('/documents/{document}/force', [DocumentController::class, 'forceDestroy'])->name('documents.force-destroy');
    Route::get('/files/{file}/edit', [DocumentController::class, 'editFile'])->name('files.edit');
});

Route::middleware(['auth', 'verified', 'permission:users.manage'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
    Route::patch('/users/{user}/role', [AdminUserController::class, 'updateRole'])->name('users.update-role');
    Route::patch('/users/{user}/permissions', [AdminUserController::class, 'syncPermissions'])->name('users.sync-permissions');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
