<?php

$file = 'app\\Http\\Controllers\\FolderController.php';

$content = <<<'PHP'
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
        $isAdmin = $request->user()->hasRole('admin');
        $userIdFilter = $request->integer('user_id') ?: null;

        // Build base query - admin puede ver todo o filtrar por usuario específico
        $folderQuery = Folder::query();
        $fileQuery = File::query();
        $documentQuery = Document::query();

        // Si no es admin o se filtra por un usuario específico, aplicar filtro de user_id
        if (!$isAdmin || $userIdFilter) {
            $targetUserId = $userIdFilter ?: $request->user()->id;
            $folderQuery->where('user_id', $targetUserId);
            $fileQuery->where('user_id', $targetUserId);
            $documentQuery->where('user_id', $targetUserId);
        } else {
            // Admin ve todo, cargar relaciones de usuario
            $folderQuery->with('user:id,name,email');
            $fileQuery->with('user:id,name,email');
            $documentQuery->with('user:id,name,email');
        }

        // Verificar que la carpeta actual exista (y respete permisos)
        if ($currentFolder !== null) {
            if ($userIdFilter) {
                $folderQuery->where('user_id', $userIdFilter);
            } elseif (!$isAdmin) {
                $folderQuery->where('user_id', $request->user()->id);
            }
            $folderQuery->findOrFail($currentFolder);
        }

        // Aplicar filtros de contexto
        if ($currentFolder !== null) {
            $folderQuery->where('parent_id', $currentFolder);
            $fileQuery->where('folder_id', $currentFolder);
            $documentQuery->where('folder_id', $currentFolder);
        } else {
            $folderQuery->whereNull('parent_id');
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
            'currentFolder' => $currentFolder,
            'breadcrumbs' => $this->breadcrumbs($currentFolder, $userIdFilter, $isAdmin),
            'showTrash' => $showTrash,
            'isAdmin' => $isAdmin,
            'userIdFilter' => $userIdFilter,
            'allUsers' => $isAdmin ? \App\Models\User::select('id', 'name', 'email')->orderBy('name')->get() : null,
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
        $folderModel->restore();

        return back()->with('success', 'Carpeta restaurada.');
    }

    public function forceDestroy(string $folder): RedirectResponse
    {
        $folderModel = Folder::withTrashed()->findOrFail($folder);
        $this->authorize('forceDelete', $folderModel);
        $folderModel->forceDelete();

        return back()->with('success', 'Carpeta eliminada definitivamente.');
    }

    private function breadcrumbs(?int $folderId, ?int $userIdFilter = null, bool $isAdmin = false): array
    {
        $breadcrumbs = [];
        $query = Folder::query();

        // Apply user filter for non-admin or specific user context
        $targetUserId = $userIdFilter ?: request()->user()->id;
        if (!$isAdmin || $userIdFilter) {
            $query->where('user_id', $targetUserId);
        }

        $folder = $folderId ? $query->find($folderId) : null;

        while ($folder !== null) {
            array_unshift($breadcrumbs, [
                'id' => $folder->id,
                'name' => $folder->name,
            ]);
            $folder = $folder->parent;
        }

        return $breadcrumbs;
    }
}
PHP;

file_put_contents($file, $content);
echo "Archivo FolderController.php reescrito correctamente" . PHP_EOL;
PHP;

file_put_contents(__DIR__ . '/_rewrite_folder.php', $content);
echo "Script creado" . PHP_EOL;