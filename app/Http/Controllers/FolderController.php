<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFolderRequest;
use App\Models\Document;
use App\Models\File;
use App\Models\Folder;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FolderController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Folder::class);

        $showTrash = $request->boolean('trash');
        $currentFolder = $request->integer('folder_id') ?: null;

        // Query base - TODOS LOS USUARIOS VENEMOS TODO EL CONTENIDO
        $folderQuery = Folder::query()->with(['user:id,name,email', 'updatedBy:id,name,email']);
        $fileQuery = File::query()->with(['user:id,name,email', 'updatedBy:id,name,email']);
        $documentQuery = Document::query()->with(['user:id,name,email', 'updatedBy:id,name,email']);

        // Aplicar filtros de contexto
        if ($currentFolder !== null) {
            $folderQuery->where('parent_id', $currentFolder);
            $fileQuery->where('folder_id', $currentFolder);
            $documentQuery->where('folder_id', $currentFolder);
        } else {
            $folderQuery->whereNull('parent_id');
            $fileQuery->whereNull('folder_id');
            $documentQuery->whereNull('folder_id');
        }

        // Aplicar orden y soft-deleted
        if ($showTrash) {
            $folders = $folderQuery->onlyTrashed()->latest()->get();
            $files = $fileQuery->onlyTrashed()->latest()->get();
            $documents = $documentQuery->onlyTrashed()->latest()->get();
        } else {
            $folders = $folderQuery->latest()->get();
            $files = $fileQuery->latest()->get();
            $documents = $documentQuery->latest()->get();
        }

        return Inertia::render('Explorer', [
            'folders' => $folders,
            'files' => $files,
            'documents' => $documents,
            'folderTree' => $this->folderTree($showTrash),
            'currentFolder' => $currentFolder,
            'breadcrumbs' => $this->breadcrumbs($currentFolder),
            'showTrash' => $showTrash,
        ]);
    }

    public function store(StoreFolderRequest $request): RedirectResponse
    {
        $this->authorize('create', Folder::class);

        $request->user()->folders()->create([
            'name' => $request->validated('name'),
            'parent_id' => $request->validated('parent_id'),
        ]);

        return back()->with('success', 'Carpeta creada correctamente.');
    }

    public function destroy(Folder $folder): RedirectResponse
    {
        $this->authorize('delete', $folder);

        DB::transaction(function () use ($folder) {
            $folder->forceFill(['updated_by_id' => request()->user()->id])->saveQuietly();
            $folder->documents()->delete();
            $folder->files()->delete();
            $folder->children()->delete();
            $folder->delete();
        });

        return back()->with('success', 'Carpeta enviada a la papelera.');
    }

    public function restore(string $folder): RedirectResponse
    {
        $folderModel = Folder::withTrashed()->findOrFail($folder);
        $this->authorize('restore', $folderModel);
        $folderModel->forceFill(['updated_by_id' => request()->user()->id])->restore();

        return back()->with('success', 'Carpeta restaurada.');
    }

    public function forceDestroy(string $folder): RedirectResponse
    {
        $folderModel = Folder::withTrashed()->findOrFail($folder);
        $this->authorize('forceDelete', $folderModel);
        $folderModel->forceDelete();

        return back()->with('success', 'Carpeta eliminada definitivamente.');
    }

    private function breadcrumbs(?int $folderId): array
    {
        $breadcrumbs = [];
        $query = Folder::query()->with('user:id,name,email');

        $folder = $folderId ? $query->find($folderId) : null;

        while ($folder !== null) {
            array_unshift($breadcrumbs, [
                'id' => $folder->id,
                'name' => $folder->name,
                'user' => $folder->user,
            ]);
            $folder = $folder->parent;
        }

        return $breadcrumbs;
    }

    private function folderTree(bool $showTrash): array
    {
        $folders = Folder::query()
            ->when($showTrash, fn ($query) => $query->onlyTrashed())
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);
        $byParent = $folders->groupBy('parent_id');

        $buildTree = function ($parentId) use (&$buildTree, $byParent): array {
            return $byParent->get($parentId, collect())->map(function (Folder $folder) use ($buildTree): array {
                return [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'parent_id' => $folder->parent_id,
                    'children' => $buildTree($folder->id),
                ];
            })->values()->all();
        };

        return $buildTree(null);
    }
}