<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFolderRequest;
use App\Models\Document;
use App\Models\File;
use App\Models\Folder;
use App\Services\AuditLogger;
use App\Services\LockManager;
use App\Services\TrashPurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FolderController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $this->authorize('viewAny', Folder::class);

        $showTrash = $request->boolean('trash');
        $currentFolder = $request->integer('folder_id') ?: null;

        // Limpieza oportunista de la papelera (elementos con +7 días): por si
        // no hay cron en el servidor. Nunca rompe la vista: fallo silencioso.
        try {
            app(TrashPurger::class)->purgeExpired();
        } catch (Throwable) {
            // Silencioso.
        }

        // Si la URL trae un folder_id que no existe (p. ej. carpeta eliminada
        // o marcador obsoleto), redirigir a la raíz: sin esto el Explorador
        // muestra un listado vacío y toda creación posterior falla con
        // "The selected parent/folder id is invalid."
        if ($currentFolder !== null) {
            $folderExists = $showTrash
                ? Folder::withTrashed()->whereKey($currentFolder)->exists()
                : Folder::whereKey($currentFolder)->exists();

            if (! $folderExists) {
                return redirect()->route('dashboard', $showTrash ? ['trash' => 1] : []);
            }
        }

        // Query base - TODOS LOS USUARIOS VENEMOS TODO EL CONTENIDO
        $folderQuery = Folder::query()->with(['user:id,name,email', 'updatedBy:id,name,email']);
        $fileQuery = File::query()->with(['user:id,name,email', 'updatedBy:id,name,email']);
        $documentQuery = Document::query()->with(['user:id,name,email', 'updatedBy:id,name,email', 'lockedBy:id,name,email']);

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

            // Evitar filas duplicadas: un archivo con documento editable enlazado
            // se muestra una sola vez como documento (conservando el original para
            // descarga) en lugar de aparecer como "archivo.doc" y "documento".
            $linkedFileIds = collect();
            $documents->each(function (Document $document) use ($files, $linkedFileIds) {
                $source = $files->firstWhere('document_id', $document->id);
                if (! $source) {
                    return;
                }

                $linkedFileIds->push($source->id);
                $document->setAttribute('linked_file', [
                    'id' => $source->id,
                    'original_name' => $source->original_name,
                    'mime_type' => $source->mime_type,
                    'file_size' => $source->file_size,
                ]);
            });

            // values(): reject() conserva las claves y una colección con huecos
            // se serializa a objeto JSON ({...}) en vez de arreglo, lo que
            // rompe props.files.map en el Explorer (página en blanco).
            $files = $files->reject(fn (File $file) => $linkedFileIds->contains($file->id))->values();
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

    /**
     * Estado ligero del Explorador para detectar cambios sin recargar la
     * página (nuevos archivos/carpetas/documentos/versiones) y para pintar en
     * tiempo real si un documento está Disponible o Bloqueado editándose en
     * Word. El Explorer compara `signature` entre tics: si cambia, marca el
     * botón de refrescar; los estados de bloqueo se aplican al vuelo.
     */
    public function state(Request $request, LockManager $lockManager): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        // Libera bloqueos caducados (Word cerrado de golpe, caída de red,
        // sesión abandonada): ningún documento queda bloqueado "para siempre".
        $lockManager->expireStale($request->integer('folder_id') ?: null, $request);

        $currentFolder = $request->integer('folder_id') ?: null;

        $folders = Folder::query()
            ->where('parent_id', $currentFolder)
            ->get(['id', 'updated_at']);

        $files = File::query()
            ->where('folder_id', $currentFolder)
            ->get(['id', 'updated_at']);

        $documents = Document::query()
            ->with(['lockedBy:id,name,email', 'currentVersion:id,updated_at'])
            ->where('folder_id', $currentFolder)
            ->get(['id', 'updated_at', 'is_locked', 'locked_by_id', 'locked_at', 'current_version_id']);

        $signature = md5(json_encode([
            $folders->map(fn (Folder $folder): array => [$folder->id, $folder->updated_at?->toISOString()]),
            $files->map(fn (File $file): array => [$file->id, $file->updated_at?->toISOString()]),
            $documents->map(fn (Document $document): array => [
                $document->id,
                $document->updated_at?->toISOString(),
                $document->is_locked,
                $document->locked_by_id,
                $document->current_version_id,
                $document->currentVersion?->updated_at?->toISOString(),
            ]),
        ]));

        return response()->json([
            'signature' => $signature,
            'documents' => $documents->map(fn (Document $document): array => [
                'id' => $document->id,
                'is_locked' => $document->is_locked,
                'locked_by' => $document->is_locked ? ($document->lockedBy?->name ?? null) : null,
                'locked_by_id' => $document->is_locked ? $document->locked_by_id : null,
                'locked_at' => $document->is_locked ? $document->locked_at?->toISOString() : null,
            ]),
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

    /**
     * Vacía la papelera por completo (sin esperar los 7 días).
     */
    public function emptyTrash(Request $request, TrashPurger $purger): RedirectResponse
    {
        abort_unless($request->user()->can('files.delete'), 403);

        $total = array_sum($purger->purgeAll());

        return back()->with('success', $total > 0
            ? "Papelera vaciada: {$total} elementos eliminados definitivamente."
            : 'La papelera ya estaba vacía.');
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