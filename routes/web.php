<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentHistoryController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\ProfileController;
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

    Route::get('/explorer/state', [FolderController::class, 'state'])
        ->middleware(['auth', 'verified'])
        ->name('explorer.state');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/explorer', [FolderController::class, 'index'])->name('explorer');
    Route::post('/trash/empty', [FolderController::class, 'emptyTrash'])->name('trash.empty');

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
    Route::post('/documents/create-word', [DocumentController::class, 'createWord'])->name('documents.create-word');
    Route::post('/documents/import-word', [DocumentController::class, 'importWord'])->name('documents.import-word');
    Route::get('/documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
    Route::patch('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::post('/documents/{document}/versions', [DocumentController::class, 'uploadVersion'])->name('documents.versions.store');
    Route::post('/documents/{document}/modify', [DocumentController::class, 'modify'])->name('documents.modify');
    Route::post('/documents/{document}/modify/cancel', [DocumentController::class, 'modifyCancel'])->name('documents.modify-cancel');
    Route::post('/documents/{document}/modify/heartbeat', [DocumentController::class, 'modifyHeartbeat'])->name('documents.modify-heartbeat');
    Route::post('/documents/{document}/unlock', [DocumentController::class, 'forceUnlock'])->name('documents.unlock');
    Route::post('/documents/{document}/sync', [DocumentController::class, 'sync'])->name('documents.sync');
    Route::post('/documents/{document}/images', [DocumentController::class, 'uploadImage'])->name('documents.images.store');
    Route::get('/documents/{document}/images/{image}', [DocumentController::class, 'showImage'])->name('documents.images.show');
    Route::get('/documents/{document}/export-pdf', [DocumentController::class, 'exportPdf'])->name('documents.export-pdf');
    Route::get('/documents/{document}/export-docx', [DocumentController::class, 'exportDocx'])->name('documents.export-docx');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('/documents/{document}/restore', [DocumentController::class, 'restore'])->name('documents.restore');
    Route::delete('/documents/{document}/force', [DocumentController::class, 'forceDestroy'])->name('documents.force-destroy');
    Route::get('/files/{file}/edit', [DocumentController::class, 'editFile'])->name('files.edit');
    Route::post('/files/{file}/link-word', [DocumentController::class, 'linkWord'])->name('files.link-word');

    Route::get('/documents/{document}/history', [DocumentHistoryController::class, 'show'])->name('documents.history');
    Route::post('/documents/versions/{version}/annotations', [DocumentHistoryController::class, 'storeAnnotation'])->name('document-versions.annotations');
    Route::get('/documents/versions/{version}/download', [DocumentHistoryController::class, 'download'])->name('document-versions.download');
});

Route::middleware(['auth', 'verified', 'permission:users.manage'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/role', [AdminUserController::class, 'updateRole'])->name('users.update-role');
    Route::patch('/users/{user}/permissions', [AdminUserController::class, 'syncPermissions'])->name('users.sync-permissions');
    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
    Route::patch('/permissions', [PermissionController::class, 'update'])->name('permissions.update');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
