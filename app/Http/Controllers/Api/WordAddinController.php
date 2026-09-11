<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\File;
use App\Models\Folder;
use App\Services\AuditLogger;
use App\Services\DocumentModifier;
use App\Services\DocumentVersionUploader;
use App\Services\LockManager;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API REST consumida por el Add-in de Word (panel de tareas Office.js).
 *
 * La edición no es colaborativa en tiempo real: un usuario "bloquea" el
 * documento (lock), trabaja en Word, sube una nueva versión con sus
 * anotaciones de cambio y lo "desbloquea". Todo el resto del código del
 * proyecto (Explorador, edición HTML/TipTap, PDF/Word) queda intacto.
 */
class WordAddinController extends Controller
{
    /**
     * Autentica al usuario del panel y devuelve un Bearer token (Sanctum).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        $user = Auth::user();

        return response()->json([
            'token' => $user->createToken('word-addin')->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Lista todos los documentos del panel (con su carpeta y estado de bloqueo),
     * tanto los que ya tienen un `.docx`/`.doc` o historial de versiones como los
     * creados desde el editor HTML: cualquier documento es editable con el Add-in.
     */
    public function documents(Request $request, LockManager $lockManager): JsonResponse
    {
        // Libera bloqueos caducados (panel cerrado sin evento de cierre):
        // la lista refleja al instante que el documento quedó disponible.
        $lockManager->expireStale(null, $request);

        $documents = Document::query()
            ->with(['folder:id,name', 'currentVersion', 'lockedBy:id,name,email'])
            ->orderBy('folder_id')
            ->orderBy('title')
            ->get();

        return response()->json([
            'documents' => $documents->map(fn (Document $document): array => $this->documentPayload($document)),
        ]);
    }

    /**
     * Contenido de una carpeta (o raíz si no se pasa `folder_id`): subcarpetas,
     * documentos y archivos sueltos, con migas de pan para navegar como en el
     * Explorador de la plataforma.
     */
    public function contents(Request $request, LockManager $lockManager): JsonResponse
    {
        $currentFolder = $request->integer('folder_id') ?: null;

        // Libera bloqueos caducados (Word cerrado de golpe, caída de red) para
        // que ningún documento quede "bloqueado para siempre".
        $lockManager->expireStale($currentFolder, $request);

        $folders = Folder::query()
            ->where('parent_id', $currentFolder)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        $documents = Document::query()
            ->with(['currentVersion', 'lockedBy:id,name,email'])
            ->where('folder_id', $currentFolder)
            ->orderBy('title')
            ->get();

        $files = File::query()
            ->where('folder_id', $currentFolder)
            ->orderBy('original_name')
            ->get(['id', 'original_name', 'mime_type', 'file_size', 'document_id']);

        // Los archivos vinculados a un documento editable se muestran como
        // documento (igual que en el Explorador) para no duplicar filas.
        $linkedDocIds = $documents->pluck('id');
        $files = $files->reject(
            fn (File $file) => $file->document_id !== null && $linkedDocIds->contains($file->document_id),
        )->values();

        $breadcrumbs = [];
        $current = $currentFolder ? Folder::query()->find($currentFolder) : null;
        while ($current !== null) {
            array_unshift($breadcrumbs, ['id' => $current->id, 'name' => $current->name]);
            $current = $current->parent;
        }

        return response()->json([
            'current_folder' => $currentFolder ? ['id' => $currentFolder, 'name' => $breadcrumbs[count($breadcrumbs) - 1]['name'] ?? null] : null,
            'breadcrumbs' => $breadcrumbs,
            'folders' => $folders,
            'documents' => $documents->map(fn (Document $document): array => $this->documentPayload($document)),
            'files' => $files->map(fn (File $file): array => [
                'id' => $file->id,
                'document_id' => $file->document_id,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'file_size' => $file->file_size,
            ]),
        ]);
    }

    private function documentPayload(Document $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'folder_id' => $document->folder_id,
            'folder_name' => $document->folder?->name,
            'file_name' => $document->wordFile()?->original_name,
            'current_version' => $document->currentVersion?->version_number,
            'is_locked' => $document->is_locked,
            'locked_by' => $document->is_locked
                ? ['id' => $document->locked_by_id, 'name' => $document->lockedBy?->name]
                : null,
            'locked_at' => $document->locked_at?->toISOString(),
            'editing_cancelled_at' => $document->editing_cancelled_at?->toISOString(),
        ];
    }

    /**
     * Asegura que el documento se puede bloquear para el usuario actual:
     *  - Si el bloqueo es de otro usuario y está vigente → HTTP 423.
     *  - Si el bloqueo es de otro usuario pero caducó (TTL superado: Word
     *    cerrado de golpe, cortó el internet, pestaña abandonada) → se libera
     *    automáticamente y el documento pasa a manos del peticionario.
     *  - Si ya es del usuario actual o está libre → lo asigna y audita.
     */
    private function ensureLockAvailable(Document $document, Request $request, AuditLogger $auditLogger, string $lockAction): void
    {
        if ($document->is_locked && $document->locked_by_id === $request->user()->id) {
            return;
        }

        $stale = $document->is_locked
            && $document->locked_at !== null
            && $document->locked_at->lessThanOrEqualTo(now()->subMinutes((int) config('addin.lock_ttl_minutes')));

        if ($document->is_locked && ! $stale) {
            throw new HttpResponseException(response()->json([
                'message' => 'El documento está siendo editado en Word por '.($document->lockedBy?->name ?? 'otro usuario').'.',
                'locked_by' => $document->lockedBy?->name,
                'locked_by_id' => $document->locked_by_id,
                'locked_at' => $document->locked_at?->toISOString(),
            ], 423));
        }

        if ($stale) {
            $auditLogger->log('addin.lock.stale_released', $document, [
                'document_id' => $document->id,
                'taken_over_by' => $request->user()->id,
            ], $request);
        }

        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $request->user()->id,
            'locked_at' => now(),
        ])->saveQuietly();

        $auditLogger->log($lockAction, $document, ['document_id' => $document->id], $request);
    }

    /**
     * Bloquea el documento para el usuario actual. Si otro usuario ya lo
     * tiene bloqueado devuelve 423 (Locked).
     */
    public function lock(Request $request, Document $document, AuditLogger $auditLogger): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        $document->refresh();

        $this->ensureLockAvailable($document, $request, $auditLogger, 'addin.document.lock');

        return response()->json(['locked' => true, 'document' => $document->id]);
    }

    /**
     * Check-Out unificado para el Add-in de Word: bloquea el documento para el
     * usuario actual (o reutiliza su bloqueo existente) y devuelve el binario
     * de la última versión en base64 para insertarlo directamente en Word con
     * `body.insertFileFromBase64(...)`. Si otro usuario posee el bloqueo se
     * responde HTTP 423 (Locked).
     */
    public function checkout(Request $request, Document $document, AuditLogger $auditLogger): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        $document->refresh();

        $this->ensureLockAvailable($document, $request, $auditLogger, 'addin.document.checkout');

        // Si el documento aún no tiene binario (creado en el editor HTML del panel),
        // se genera un `.docx` real a partir de su contenido y se registra como v1.
        $path = $this->ensureBinaryForDocument($document, $request);
        $disk = config('filesystems.default');

        abort_unless(Storage::disk($disk)->exists($path), 404, 'El documento no tiene archivo.');

        $buffer = (string) Storage::disk($disk)->get($path);
        abort_if(strlen($buffer) > (int) config('addin.max_file_size', 20 * 1024 * 1024), 413, 'El documento es demasiado grande para abrirlo en Word.');

        return response()->json([
            'locked' => true,
            'document' => $document->id,
            'title' => $document->title,
            'file_name' => $this->downloadName($document, $document->currentVersion?->version_number),
            'file_base64' => base64_encode($buffer),
        ]);
    }

    /**
     * Versión del Check-Out para el flujo de descarga nativa (fidelidad 100 %):
     * bloquea el documento y devuelve el `.docx` REAL con la custom property
     * `plataforma_doc_id` inyectada (sin regenerar nada). El panel lo descarga,
     * el usuario lo abre en Word y al detectar el metadato se activa la vista
     * de "Guardar versión".
     */
    public function modify(Request $request, Document $document, AuditLogger $auditLogger, DocumentModifier $modifier): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        $document->refresh();

        $this->ensureLockAvailable($document, $request, $auditLogger, 'addin.document.modify');

        $path = $this->ensureBinaryForDocument($document, $request);
        $disk = config('filesystems.default');

        abort_unless(Storage::disk($disk)->exists($path), 404, 'El documento no tiene archivo.');

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_unless($extension === 'docx', 415, 'Solo los .docx se editan en Word con fidelidad total. Abre el .doc en Word y usa "Guardar como" → .docx.');

        $buffer = (string) Storage::disk($disk)->get($path);
        abort_if(strlen($buffer) > (int) config('addin.max_file_size', 20 * 1024 * 1024), 413, 'El documento es demasiado grande para abrirlo en Word.');

        try {
            $injected = $modifier->injectMetadata($buffer, $document->id);
        } catch (\Throwable $exception) {
            $lockManager = app(LockManager::class);
            if ($document->fresh()->is_locked && $document->fresh()->locked_by_id === $request->user()->id) {
                $lockManager->release($document, $request, 'addin.document.modify_released_on_error', [
                    'reason' => $exception->getMessage(),
                ]);
            }
            throw $exception;
        }

        $document->forceFill(['editing_cancelled_at' => null])->saveQuietly();

        return response()->json([
            'locked' => true,
            'document' => $document->id,
            'title' => $document->title,
            'file_name' => $this->downloadName($document, $document->currentVersion?->version_number),
            'file_base64' => base64_encode($injected),
        ]);
    }

    /**
     * Estado de la sesión de edición detectada por metadatos. Responde si el
     * documento Word abierto corresponde a una edición ACTIVA del usuario
     * (bloqueado por él y no cancelada desde la plataforma) o si por el
     * contrario fue cancelada / pertenece a otro usuario.
     */
    public function session(Request $request, Document $document): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        $document->refresh();

        $lockedByMe = $document->is_locked && $document->locked_by_id === $request->user()->id;
        $cancelled = $document->editing_cancelled_at !== null;

        return response()->json([
            'document' => $this->documentPayload($document),
            'session' => [
                'active' => $lockedByMe && ! $cancelled,
                'cancelled' => $cancelled,
                'locked_by_me' => $lockedByMe,
                'editing_cancelled_at' => $document->editing_cancelled_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Entrega la última versión del `.docx` para abrirla en Word.
     * Requiere que el peticionario tenga el bloqueo activo.
     */
    public function download(Request $request, Document $document): StreamedResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        abort_unless(
            $document->is_locked && $document->locked_by_id === $request->user()->id,
            423,
            'Debes bloquear el documento antes de descargarlo y abrirlo en Word.',
        );

        $path = $this->currentBinaryPath($document);
        $disk = config('filesystems.default');

        abort_unless($path !== null && Storage::disk($disk)->exists($path), 404, 'El documento no tiene archivo.');

        $name = $this->downloadName($document, $document->currentVersion?->version_number);

        return Storage::disk($disk)->download($path, $name);
    }

    /**
     * Recibe el `.docx` editado en Word + las anotaciones de cambio.
     * Incrementa la versión, guarda el binario y actualiza la versión activa.
     */
    public function upload(Request $request, Document $document, AuditLogger $auditLogger, DocumentVersionUploader $uploader): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        $document->refresh();

        if ($document->editing_cancelled_at !== null) {
            return response()->json([
                'message' => 'La edición fue cancelada desde la plataforma. Cierra este documento y usa "Modificar" de nuevo en el Explorador.',
            ], 409);
        }

        abort_unless(
            $document->is_locked && $document->locked_by_id === $request->user()->id,
            423,
            'Debes tener el bloqueo del documento para guardar cambios.',
        );

        $request->validate([
            'file' => ['required', 'file', 'mimes:doc,docx'],
            'change_notes' => ['required', 'string', 'max:5000'],
        ]);

        $version = $uploader->upload(
            document: $document,
            uploadedFile: $request->file('file'),
            userId: $request->user()->id,
            changeSummary: (string) $request->input('change_notes'),
            source: 'upload',
        );

        $document->forceFill(['editing_cancelled_at' => null])->saveQuietly();

        $auditLogger->log('addin.document.upload', $version, [
            'document_id' => $document->id,
            'version_number' => $version->version_number,
        ], $request);

        return response()->json([
            'saved' => true,
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
            ],
        ]);
    }

    /**
     * Libera el bloqueo del documento (solo su titular).
     */
    public function unlock(Request $request, Document $document, AuditLogger $auditLogger): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        abort_unless($document->locked_by_id === $request->user()->id, 403, 'Solo el titular del bloqueo puede liberarlo.');

        $document->forceFill([
            'is_locked' => false,
            'locked_by_id' => null,
            'locked_at' => null,
        ])->saveQuietly();

        $auditLogger->log('addin.document.unlock', $document, ['document_id' => $document->id], $request);

        return response()->json(['unlocked' => true, 'document' => $document->id]);
    }

    /**
     * Latido del Add-in: renueva la marca de actividad del bloqueo para que el
     * TTL no expire mientras el usuario edita en Word. No audita (evita ruido
     * en el registro) y NO toca `updated_at`, así la firma del Explorador no
     * dispara recargas en cada latido.
     *
     * Si el bloqueo ya no es del peticionario (caducó y otro lo tomó, o algún
     * editor lo liberó) responde 423 para que el panel cierre la sesión.
     */
    public function heartbeat(Request $request, Document $document): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        $document->refresh();

        if ($document->editing_cancelled_at !== null) {
            return response()->json([
                'message' => 'La edición fue cancelada desde la plataforma. Cierra este documento y usa "Modificar" de nuevo en el Explorador.',
            ], 409);
        }

        if (! $document->is_locked) {
            return response()->json([
                'message' => 'El documento ya no está bloqueado.',
            ], 409);
        }

        if ($document->locked_by_id !== $request->user()->id) {
            return response()->json([
                'message' => 'El bloqueo ya no es tuyo (caducó o fue liberado).',
                'locked_by' => $document->lockedBy?->name,
                'locked_by_id' => $document->locked_by_id,
                'locked_at' => $document->locked_at?->toISOString(),
            ], 423);
        }

        Document::query()->whereKey($document->getKey())->update([
            'locked_at' => now(),
        ]);

        return response()->json(['ok' => true, 'locked' => true, 'document' => $document->id]);
    }

    /**
     * Cierre de sesión del panel: revoca el token activo y libera cualquier
     * bloqueo del usuario para no dejar documentos cerrados a edición.
     */
    public function logout(Request $request, LockManager $lockManager): JsonResponse
    {
        $user = $request->user();

        $released = $lockManager->releaseForUser($user->getKey(), $request);

        $user->currentAccessToken()?->delete();

        return response()->json(['logged_out' => true, 'released_locks' => $released]);
    }

    /**
     * Historial de versiones de un documento: autor, fecha, anotaciones de
     * cambio y comentarios de la plataforma.
     */
    public function history(Request $request, Document $document): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);

        $versions = $document->versions()
            ->with(['user:id,name,email', 'annotations.user:id,name,email'])
            ->get(['id', 'document_id', 'user_id', 'file_path', 'version_number', 'change_summary', 'file_size', 'created_at']);

        return response()->json([
            'document' => ['id' => $document->id, 'title' => $document->title],
            'versions' => $versions->map(fn (DocumentVersion $version): array => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'change_summary' => $version->change_summary,
                'file_size' => $version->file_size,
                'created_at' => $version->created_at->toISOString(),
                'user' => [
                    'id' => $version->user?->id,
                    'name' => $version->user?->name,
                    'email' => $version->user?->email,
                ],
                'annotations' => $version->annotations->map(fn ($annotation): array => [
                    'id' => $annotation->id,
                    'comment' => $annotation->comment,
                    'created_at' => $annotation->created_at->toISOString(),
                    'user' => [
                        'id' => $annotation->user?->id,
                        'name' => $annotation->user?->name,
                    ],
                ]),
            ]),
        ]);
    }

    /**
     * Vincula un `.docx`/`.doc` subido en la plataforma a un documento editable
     * (reutilizando un documento huérfano con el mismo nombre, igual que hace el
     * Explorador). Devuelve el documento para poder hacer Check-Out de inmediato.
     */
    public function linkFile(Request $request, File $file, AuditLogger $auditLogger): JsonResponse
    {
        abort_unless($request->user()->can('files.view'), 403);
        abort_unless($request->user()->can('docs.create'), 403, 'No tienes permiso para crear documentos.');

        abort_unless(in_array($file->extension(), ['docx', 'doc'], true), 415, 'Solo se pueden vincular archivos Word.');

        if ($file->document_id && $file->document) {
            return response()->json(['document' => $this->documentPayload($file->document)]);
        }

        $orphan = Document::query()
            ->where('title', pathinfo($file->original_name, PATHINFO_FILENAME))
            ->whereDoesntHave('files')
            ->orderBy('id')
            ->first();

        $document = $orphan ?? Document::create([
            'title' => pathinfo($file->original_name, PATHINFO_FILENAME),
            'content' => '<p></p>',
            'folder_id' => $file->folder_id,
            'user_id' => $request->user()->id,
            'imported_from' => $file->original_name,
        ]);

        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'user_id' => $request->user()->id,
            'file_path' => $file->storage_path,
            'file_size' => $file->file_size,
            'version_number' => 'v1',
            'change_summary' => 'Versión inicial del archivo subido',
        ]);

        $document->forceFill([
            'current_version_id' => $version->id,
            'updated_by_id' => $request->user()->id,
        ])->saveQuietly();

        $file->forceFill(['document_id' => $document->id])->saveQuietly();

        $auditLogger->log('document.link_word', $document, ['original_name' => $file->original_name], $request);

        return response()->json(['document' => $this->documentPayload($document)]);
    }

    /**
     * Ruta binaria vigente para descargar: la última versión del Add-in o, si
     * aún no hay versiones, el archivo `.docx/.doc` vinculado al documento.
     */
    private function currentBinaryPath(Document $document): ?string
    {
        $latest = $document->versions()->latest('id')->first();

        if ($latest !== null) {
            return $latest->file_path;
        }

        return $document->wordFile()?->storage_path;
    }

    /**
     * Devuelve un `.docx` válido para el documento, generándolo bajo demanda si
     * aún no existe ninguno: SOLO un documento creado y NO tocado en el editor
     * HTML (en blanco) recibe así un primer binario con el título como contenido.
     *
     * Si el documento tiene contenido real del editor web (texto, imágenes,
     * tablas) y no conserva su archivo Word original, NO se fabrica un `.docx`
     * a partir del HTML: la conversión HTML→docx pierde márgenes, encabezados y
     * pies, tabulaciones y recursos como imágenes WMF/EMF, y ese `.docx` falso
     * pasaría a ser tratado como "el original". En ese caso se responde 409 y se
     * pide subir/vincular el archivo `.docx` real (fidelidad al 100 %).
     */
    private function ensureBinaryForDocument(Document $document, Request $request): string
    {
        $existing = $this->currentBinaryPath($document);
        if ($existing !== null && Storage::disk(config('filesystems.default'))->exists($existing)) {
            return $existing;
        }

        $plain = trim((string) preg_replace('/<[^>]+>/', ' ', (string) $document->content));
        if ($plain !== '') {
            abort(409, 'Este documento solo tiene contenido del editor web y no conserva su archivo Word original. ' .
                'Para mantener el formato al 100 %, sube el archivo .docx original (Subir versión) y vuelve a abrirlo en Word.');
        }

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText($document->title, ['bold' => true, 'size' => 16]);

        $tempPath = tempnam(sys_get_temp_dir(), 'pdoc');
        abort_if($tempPath === false, 500, 'No se pudo generar el documento de Word.');
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $buffer = (string) file_get_contents($tempPath);
        @unlink($tempPath);

        $disk = config('filesystems.default');
        $newPath = 'documents/'.$document->id.'/versions/'.Str::uuid().'.docx';
        Storage::disk($disk)->put($newPath, $buffer);

        $versionNumber = 'v'.($document->versions()->count() + 1);
        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'user_id' => $request->user()->id,
            'file_path' => $newPath,
            'file_size' => strlen($buffer),
            'version_number' => $versionNumber,
            'change_summary' => 'Versión inicial generada desde la plataforma.',
        ]);

        $document->forceFill([
            'current_version_id' => $version->id,
            'updated_by_id' => $request->user()->id,
        ])->saveQuietly();

        if (! $document->wordFile() instanceof File) {
            $file = File::create([
                'name' => pathinfo($document->title, PATHINFO_FILENAME),
                'original_name' => $document->title.'.docx',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'storage_path' => $newPath,
                'file_size' => strlen($buffer),
                'folder_id' => $document->folder_id,
                'user_id' => $request->user()->id,
            ]);
            $file->forceFill(['document_id' => $document->id])->saveQuietly();
        }

        return $newPath;
    }

    private function downloadName(Document $document, ?string $versionNumber): string
    {
        $base = Str::slug($document->title) ?: 'documento';
        $suffix = $versionNumber ? '.'.$versionNumber : '';
        $extension = $document->wordFile()?->extension() ?? 'docx';

        return $base.$suffix.'.'.$extension;
    }
}