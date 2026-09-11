<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportWordRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\SyncDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Requests\UploadDocumentImageRequest;
use App\Events\DocumentUpdated;
use App\Models\Document;
use App\Models\DocumentImage;
use App\Models\DocumentVersion;
use App\Models\File;
use App\Models\Folder;
use App\Services\WordDocumentImporter;
use App\Services\DocumentVersionUploader;
use App\Services\DocumentModifier;
use App\Services\DocumentPdfExporter;
use App\Services\AuditLogger;
use App\Services\LockManager;
use App\Services\DocumentImageService;
use App\Services\WordDocumentLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DocumentController extends Controller
{
    public function create(StoreDocumentRequest $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $document = Document::create([
            'title' => $request->validated('title'),
            'folder_id' => $request->validated('folder_id'),
            'user_id' => $request->user()->id,
            'content' => '<p></p>',
            'platform_created' => true,
        ]);

        // Binario .docx inicial + v1: el documento nace con Modificar,
        // Subir versión e Historial, igual que un archivo subido.
        $this->createInitialBinary($document, $request->user()->id, null, 'Documento inicial');

        // Redirección (PRG) en vez de render directo: si se renderiza el Editor
        // con 200 sobre la URL del POST (/documents, sin ruta GET), cualquier
        // router.reload() o F5 posterior falla con 405 Method Not Allowed.
        return redirect()->route('documents.edit', $document);
    }

    public function edit(Document $document): Response
    {
        $this->authorize('view', $document);

        $user = request()->user();

        $siblings = Document::query()
            ->where('folder_id', $document->folder_id)
            ->orderBy('title')
            ->get(['id', 'title', 'folder_id']);

        return Inertia::render('Editor', [
            'document' => $document,
            'canEdit' => $user->can('docs.edit_realtime') || $document->user_id === $user->id,
            'siblings' => $siblings,
        ]);
    }

    public function update(UpdateDocumentRequest $request, Document $document, DocumentImageService $images): JsonResponse
    {
        $this->authorize('update', $document);
        $userId = $request->user()->id;
        $validated = $request->validated();
        if (isset($validated['content']) && is_string($validated['content'])) {
            $validated['content'] = $images->extractEmbeddedImages($validated['content'], $document, $request->user());
        }
        $document->update(array_merge($validated, [
            'updated_by_id' => $userId,
        ]));
        $this->markFirstEdit($document, $userId);

        return response()->json([
            'saved' => true,
            'document' => $document->only(['id', 'title', 'updated_at']),
        ]);
    }

    public function sync(SyncDocumentRequest $request, Document $document, DocumentImageService $images): JsonResponse
    {
        $this->authorize('update', $document);

        $content = $request->validated('content');
        if (is_string($content)) {
            $content = $images->extractEmbeddedImages($content, $document, $request->user());
        }
        if ($content !== null) {
            $document->update([
                'content' => $content,
                'updated_by_id' => $request->user()->id,
            ]);
            $this->markFirstEdit($document, $request->user()->id);
        }

        // Colaboración en vivo "best-effort": el contenido YA quedó guardado
        // arriba. Si Reverb está caído, no convertir el guardado en un 500.
        $broadcast = true;
        try {
            event(new DocumentUpdated(
                documentId: $document->id,
                userId: $request->user()->id,
                delta: $request->validated('delta', ''),
                content: $content,
            ));
        } catch (Throwable $exception) {
            $broadcast = false;
            Log::warning('Realtime broadcast failed; content was still saved.', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json(['broadcast' => $broadcast]);
    }

    /**
     * Sube una imagen del editor de la plataforma y devuelve su URL relativa.
     * Guardar la URL (no el base64) mantiene los UPDATEs pequeños y evita
     * superar max_allowed_packet de MySQL.
     */
    public function uploadImage(UploadDocumentImageRequest $request, Document $document, DocumentImageService $images): JsonResponse
    {
        $this->authorize('update', $document);

        $uploaded = $request->file('image');
        $extension = strtolower($uploaded->getClientOriginalExtension());
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        $file = $images->storeImageFile(
            (string) $uploaded->getContent(),
            $extension,
            $document,
            $request->user(),
            pathinfo($uploaded->getClientOriginalName(), PATHINFO_FILENAME),
        );

        return response()->json([
            'url' => route('documents.images.show', [$document->id, $file->id], false),
            'id' => $file->id,
        ]);
    }

    /**
     * Sirve una imagen vinculada al documento. Requiere permiso de vista
     * sobre el documento (las URLs son relativas y viajan con la sesión).
     */
    public function showImage(Document $document, DocumentImage $image): BinaryFileResponse
    {
        abort_if((int) $image->document_id !== (int) $document->id, 404);
        $this->authorize('view', $document);

        $disk = config('filesystems.default');
        abort_unless(Storage::disk($disk)->exists($image->storage_path), 404);

        return response()->file(Storage::disk($disk)->path($image->storage_path), [
            'Content-Type' => $image->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Sube una nueva versión de un documento directamente desde la plataforma
     * (Explorador → "Subir versión"). El archivo se convierte en la versión
     * activa (aunque cambie el nombre), se sincroniza el binario vinculado y se
     * regenera el HTML para la previsualización.
     *
     * Exclusión mutua con el Add-in de Word:
     *  - si el documento está bloqueado por Word (y el bloqueo está vigente) → 409.
     *  - mientras dura la subida se marca como bloqueado para que el Add-in no
     *    pueda hacer Check-Out; el bloqueo se libera SIEMPRE en `finally`.
     */
    public function uploadVersion(Request $request, Document $document, AuditLogger $auditLogger, DocumentVersionUploader $uploader): JsonResponse
    {
        abort_unless(
            $request->user()->can('docs.edit_realtime') || $request->user()->can('files.upload'),
            403,
            'Sin permiso para subir versiones.',
        );

        $request->validate([
            'file' => ['required', 'file', 'mimes:doc,docx'],
            'change_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $document->refresh();

        if ($document->is_locked) {
            $stale = $document->locked_at !== null
                && $document->locked_at->lessThanOrEqualTo(now()->subMinutes((int) config('addin.lock_ttl_minutes')));

            if (! $stale && $document->locked_by_id !== $request->user()->id) {
                return response()->json([
                    'message' => 'El documento está siendo editado en Word por '.($document->lockedBy?->name ?? 'otro usuario').'. Libera su bloqueo antes de subir una versión manual.',
                    'locked_by' => $document->lockedBy?->name,
                    'locked_by_id' => $document->locked_by_id,
                    'locked_at' => $document->locked_at?->toISOString(),
                ], 409);
            }

            if ($stale && $document->locked_by_id !== $request->user()->id) {
                $auditLogger->log('document.version.stale_released', $document, [
                    'document_id' => $document->id,
                    'taken_over_by' => $request->user()->id,
                ], $request);
            }
        }

        // Bloqueo temporal y exclusivo: los Add-in de Word abiertos verán el
        // documento como bloqueado y su Check-Out recibirá 423 hasta terminar.
        $document->forceFill([
            'is_locked' => true,
            'locked_by_id' => $request->user()->id,
            'locked_at' => now(),
        ])->saveQuietly();

        try {
            $version = $uploader->upload(
                document: $document,
                uploadedFile: $request->file('file'),
                userId: $request->user()->id,
                changeSummary: (string) $request->input('change_notes', 'Subida manual de versión desde la plataforma.'),
                source: 'platform',
                updateLinkedName: true,
            );

            $auditLogger->log('document.version.upload', $version, [
                'document_id' => $document->id,
                'version_number' => $version->version_number,
            ], $request);

            $document->forceFill(['editing_cancelled_at' => null])->saveQuietly();

            return response()->json([
                'saved' => true,
                'version' => [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                ],
            ]);
        } finally {
            // Se gane, se cancele o falle, el documento jamás queda bloqueado.
            $document->forceFill([
                'is_locked' => false,
                'locked_by_id' => null,
                'locked_at' => null,
            ])->saveQuietly();
        }
    }

    /**
     * "Modificar en Word" (fidelidad 100 %): descarga el `.docx` vigente TAL
     * CUAL, con la propiedad custom `plataforma_doc_id` inyectada para que el
     * Add-in reconozca la sesión. El documento queda bloqueado para el usuario
     * hasta que suba el archivo editado (modal o Add-in) o cancele.
     */
    public function modify(Request $request, Document $document, AuditLogger $auditLogger, LockManager $lockManager, DocumentModifier $modifier): BinaryFileResponse|JsonResponse
    {
        $this->authorize('view', $document);
        abort_unless($request->user()->can('files.download'), 403, 'Sin permiso para descargar documentos.');

        $document->refresh();

        // Validaciones del binario ANTES de tomar el bloqueo: si el documento no
        // se puede servir (sin archivo, extensión no editable, demasiado grande)
        // nadie debe quedarse colgado con un bloqueo sin descarga.
        $disk = config('filesystems.default');
        $binaryPath = $this->currentBinaryPath($document);

        abort_unless($binaryPath !== null && Storage::disk($disk)->exists($binaryPath), 404, 'El documento no tiene archivo Word.');

        $extension = strtolower(pathinfo($binaryPath, PATHINFO_EXTENSION));
        abort_unless($extension === 'docx', 415, 'Solo los .docx se editan en Word con fidelidad total. Abre el .doc en Word y usa "Guardar como" → .docx.');

        $buffer = (string) Storage::disk($disk)->get($binaryPath);
        abort_if(strlen($buffer) > (int) config('addin.max_file_size', 20 * 1024 * 1024), 413, 'El documento es demasiado grande para editar en Word.');

        $lockManager->acquire($document, $request->user(), $request, 'document.modify');

        try {
            $injected = $modifier->injectMetadata($buffer, $document->id);
        } catch (Throwable $exception) {
            $lockManager->release($document, $request, 'document.modify_released_on_error', [
                'reason' => $exception->getMessage(),
            ]);
            Log::error('Document metadata injection failed.', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo inyectar los metadatos de edición al .docx. El archivo podría estar corrupto.',
            ], 422);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'pdmoddl');
        abort_if($tempPath === false, 500, 'No se pudo crear el archivo de descarga.');
        file_put_contents($tempPath, $injected);

        $document->forceFill(['editing_cancelled_at' => null])->saveQuietly();

        $auditLogger->log('document.modify', $document, ['document_id' => $document->id], $request);

        return response()->download(
            $tempPath,
            Str::slug($document->title).'.docx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        )->deleteFileAfterSend(true);
    }

    /**
     * Cancela la edición desde la plataforma: libera el bloqueo, marca el
     * documento como "cancelado" para que el Add-in rechace cualquier
     * Check-In con el mensaje «La edición fue cancelada desde la plataforma».
     */
    public function modifyCancel(Request $request, Document $document, AuditLogger $auditLogger, LockManager $lockManager): JsonResponse
    {
        $this->authorize('view', $document);
        abort_unless($request->user()->can('files.download'), 403, 'Sin permiso para este documento.');

        $document->refresh();

        Document::query()->whereKey($document->getKey())->update([
            'editing_cancelled_at' => now(),
        ]);

        $wasLocked = $document->is_locked;
        if ($wasLocked) {
            $lockManager->release($document, $request, 'document.modify_cancelled', [
                'cancelled_by' => $request->user()->id,
            ]);
        } else {
            $auditLogger->log('document.modify_cancelled', $document, [
                'document_id' => $document->id,
                'cancelled_by' => $request->user()->id,
            ], $request);
        }

        return response()->json(['cancelled' => true, 'document' => $document->id]);
    }

    /**
     * Latido del modal "Modificar": renueva el TTL del bloqueo mientras la
     * plataforma aguarda a que el usuario confirme (check-in del Add-in) o
     * cancele. No audita y no toca `updated_at` para no disparar recargas.
     */
    public function modifyHeartbeat(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document->refresh();

        if (! $document->is_locked) {
            return response()->json(['message' => 'El documento ya no está bloqueado.'], 409);
        }

        if ($document->locked_by_id !== $request->user()->id) {
            return response()->json([
                'message' => 'El bloqueo ya no es tuyo (caducó o fue liberado).',
                'locked_by' => $document->lockedBy?->name,
                'locked_by_id' => $document->locked_by_id,
                'locked_at' => $document->locked_at?->toISOString(),
            ], 423);
        }

        Document::query()->whereKey($document->getKey())->update(['locked_at' => now()]);

        return response()->json(['ok' => true, 'locked' => true, 'document' => $document->id]);
    }

    public function importWord(ImportWordRequest $request, WordDocumentImporter $importer, AuditLogger $auditLogger): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Document::class);
        $uploadedFile = $request->file('file');
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        $sourcePath = $uploadedFile->getRealPath();

        try {
            $content = $importer->toHtml($sourcePath, $extension);
            $document = Document::create([
                'title' => pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME),
                'content' => $content,
                'folder_id' => $request->validated('folder_id'),
                'user_id' => $request->user()->id,
                'imported_from' => $uploadedFile->getClientOriginalName(),
            ]);

            // Conserva SIEMPRE el binario original como v1 (archivo vinculado):
            // el Add-in de Word y el Explorador sirven el archivo tal cual, sin
            // regenerarlo desde el HTML. Así, un documento con muchas imágenes,
            // líneas o tablas complejas se ve idéntico al original al editarlo
            // en Word (regenerarlo desde HTML perdería fidelidad visual).
            $disk = config('filesystems.default');
            $storagePath = 'files/'.$request->user()->id.'/word/'.Str::uuid().'.'.$extension;
            Storage::disk($disk)->put($storagePath, (string) $uploadedFile->getContent());

            $file = File::create([
                'name' => pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME),
                'original_name' => $uploadedFile->getClientOriginalName(),
                'mime_type' => $extension === 'doc'
                    ? 'application/msword'
                    : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'storage_path' => $storagePath,
                'file_size' => $uploadedFile->getSize(),
                'folder_id' => $request->validated('folder_id'),
                'user_id' => $request->user()->id,
            ]);

            $file->forceFill(['document_id' => $document->id])->saveQuietly();

            $this->registerInitialVersionForDocument($document, $file, $request->user()->id, 'Versión inicial del archivo subido');

            $auditLogger->log('document.import_word', $document, [
                'original_name' => $uploadedFile->getClientOriginalName(),
            ]);

            if ($request->expectsJson()) {
                return response()->json(['id' => $document->id]);
            }

            return redirect()->route('documents.edit', $document);
        } catch (Throwable $exception) {
            Log::error('Word document import failed.', [
                'user_id' => $request->user()->id,
                'filename' => $uploadedFile->getClientOriginalName(),
                'error' => $exception->getMessage(),
            ]);

            return back()->withErrors([
                'file' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Crea un documento Word (.docx) con su binario inicial y lo registra en
     * el historial de versiones (v1) para editarlo después con el Add-in de Word.
     */
    public function createWord(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $title = trim((string) $request->input('title', ''));
        abort_if($title === '', 422, 'El título es obligatorio.');
        abort_if(mb_strlen($title) > 120, 422, 'El título no puede superar 120 caracteres.');

        $folderId = $request->integer('folder_id');
        $folderId = $folderId > 0 && Folder::query()->whereKey($folderId)->exists() ? $folderId : null;

        $document = Document::create([
            'title' => $title,
            'content' => '<p></p>',
            'folder_id' => $folderId,
            'user_id' => $request->user()->id,
            'imported_from' => $title.'.docx',
            'platform_created' => true,
        ]);

        $this->createInitialBinary($document, $request->user()->id, $title, 'Documento inicial');

        $auditLogger->log('document.create_word', $document, ['title' => $title]);

        return redirect()->route('documents.history', $document);
    }

    /**
     * Vincula un `.docx`/`.doc` subido a un documento editable por Add-in.
     * Reutiliza un documento huérfano con el mismo nombre si existe y no está
     * vinculado a ningún archivo.
     */
    public function linkWord(File $file, AuditLogger $auditLogger, WordDocumentLinker $linker): RedirectResponse|JsonResponse
    {
        $this->authorize('view', $file);
        abort_unless(request()->user()->can('docs.create'), 403);

        abort_unless(in_array($file->extension(), ['docx', 'doc'], true), 415, 'Solo se pueden vincular archivos Word.');

        $document = $linker->link($file, request()->user());

        $auditLogger->log('document.link_word', $document, ['original_name' => $file->original_name]);

        if (request()->wantsJson()) {
            return response()->json([
                'document_id' => $document->id,
                'redirect' => route('documents.history', $document),
            ]);
        }

        return redirect()->route('documents.history', $document);
    }

    /**
     * Genera el binario .docx inicial de un documento creado en la plataforma
     * y lo registra como v1: así nace con Modificar, Subir versión e
     * Historial, igual que un archivo subido.
     */
    private function createInitialBinary(Document $document, int $userId, ?string $heading, string $summary): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        if ($heading !== null && trim($heading) !== '') {
            $section->addText($heading, ['bold' => true, 'size' => 16]);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'pdoc');
        abort_if($tempPath === false, 500, 'No se pudo crear el archivo temporal.');
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);
        $buffer = (string) file_get_contents($tempPath);
        @unlink($tempPath);

        $disk = config('filesystems.default');
        $storagePath = 'files/'.$userId.'/word/'.Str::uuid().'.docx';
        Storage::disk($disk)->put($storagePath, $buffer);

        $baseName = $heading !== null && trim($heading) !== '' ? $heading : 'Documento';
        $file = File::create([
            'name' => pathinfo($baseName, PATHINFO_FILENAME),
            'original_name' => $baseName.'.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'storage_path' => $storagePath,
            'file_size' => strlen($buffer),
            'folder_id' => $document->folder_id,
            'user_id' => $userId,
        ]);

        $file->forceFill(['document_id' => $document->id])->saveQuietly();

        $this->registerInitialVersionForDocument($document, $file, $userId, $summary);
    }

    /**
     * Registra la versión inicial (v1) de un documento y la marca como activa.
     */
    private function registerInitialVersionForDocument(
        Document $document,
        File $file,
        int $userId,
        string $changeSummary,
    ): DocumentVersion {
        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'user_id' => $userId,
            'file_path' => $file->storage_path,
            'file_size' => $file->file_size,
            'version_number' => 'v1',
            'change_summary' => $changeSummary,
        ]);

        $document->forceFill([
            'current_version_id' => $version->id,
            'updated_by_id' => $userId,
        ])->saveQuietly();

        return $version;
    }

    public function editFile(File $file, WordDocumentImporter $importer): RedirectResponse
    {
        $this->authorize('view', $file);
        abort_unless(request()->user()->can('docs.edit_realtime'), 403);

        try {
            $document = DB::transaction(function () use ($file, $importer) {
            $lockedFile = File::query()->with('document')->lockForUpdate()->findOrFail($file->id);

            if ($lockedFile->document_id && $lockedFile->document) {
                $existing = $lockedFile->document;
                $existingContent = (string) $existing->content;

                if (! str_contains($existingContent, 'pdoc-editor-version:4')
                    && (str_contains($existingContent, 'pdoc-editor-version:2')
                        || str_contains($existingContent, 'pdoc-editor-version:3')
                        || str_contains($existingContent, 'class="word-tab"'))) {
                    $sourcePath = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default'))->path($lockedFile->storage_path);
                    $content = $importer->toHtml($sourcePath, pathinfo($lockedFile->original_name, PATHINFO_EXTENSION));
                    $existing->forceFill([
                        'content' => $content,
                        'imported_from' => $existing->imported_from ?? $lockedFile->original_name,
                        'updated_by_id' => request()->user()->id,
                    ])->save();
                }

                return $existing;
            }

            // Reutilizar documento huérfano con el mismo nombre en la misma carpeta:
            // evita que "archivo.doc" + un documento sin enlazar del mismo nombre
            // aparezcan como dos filas duplicadas en el explorador.
            $orphanDocument = Document::query()
                ->where('title', pathinfo($lockedFile->original_name, PATHINFO_FILENAME))
                ->where(fn ($query) => $query->whereNull('folder_id')->orWhere('folder_id', $lockedFile->folder_id))
                ->whereDoesntHave('files')
                ->orderBy('id')
                ->first();

            if ($orphanDocument) {
                $orphanDocument->forceFill([
                    'imported_from' => $orphanDocument->imported_from ?? $lockedFile->original_name,
                    'updated_by_id' => request()->user()->id,
                ])->save();
                $lockedFile->update([
                    'document_id' => $orphanDocument->id,
                    'updated_by_id' => request()->user()->id,
                ]);

                return $orphanDocument;
            }

            $disk = config('filesystems.default');
            $sourcePath = \Illuminate\Support\Facades\Storage::disk($disk)->path($lockedFile->storage_path);
            $extension = strtolower(pathinfo($lockedFile->original_name, PATHINFO_EXTENSION));

            if ($extension === 'doc') {
                throw new RuntimeException(
                    'Los archivos .doc no se pueden editar directamente. Abre el archivo en Word y usa "Guardar como" → .docx.'
                );
            }

            $content = $importer->toHtml($sourcePath, $extension);
            $document = Document::create([
                'title' => pathinfo($lockedFile->original_name, PATHINFO_FILENAME),
                'content' => $content,
                'folder_id' => $lockedFile->folder_id,
                'user_id' => request()->user()->id,
                'imported_from' => $lockedFile->original_name,
            ]);
            $lockedFile->update([
                'document_id' => $document->id,
                'updated_by_id' => request()->user()->id,
            ]);

            // Registra v1 apuntando al archivo original: el Add-in edita en Word
            // el binario original (fiel), no una versión regenerada desde HTML.
            $this->registerInitialVersionForDocument($document, $lockedFile, request()->user()->id, 'Versión inicial del archivo subido');

            return $document;
            });

            return redirect()->route('documents.edit', $document);
        } catch (Throwable $exception) {
            Log::error('Word document conversion failed on edit.', [
                'file_id' => $file->id,
                'original_name' => $file->original_name,
                'error' => $exception->getMessage(),
                'previous' => $exception->getPrevious()?->getMessage(),
            ]);

            return back()->withErrors([
                'file' => 'No se pudo preparar este documento para editar. Usa la vista previa para conservar el diseño original.',
            ]);
        }
    }

    /**
     * Libera manualmente el bloqueo de un documento (documentos atascados por
     * una sesión de Word cerrada de golpe o una caída de red). Solo el titular
     * del bloqueo o un editor/admin puede forzar la liberación.
     */
    public function forceUnlock(Request $request, Document $document, LockManager $lockManager): RedirectResponse
    {
        abort_unless(
            $lockManager->canForceUnlock($document, $request->user()),
            403,
            'No tienes permiso para liberar este bloqueo.',
        );

        if ($document->is_locked) {
            $holderId = $document->locked_by_id;
            $lockManager->release($document, $request, 'addin.lock.force_released', [
                'lock_holder_id' => $holderId,
                'forced_by' => $request->user()->id,
            ]);
        }

        return back()->with('success', 'Bloqueo liberado manualmente.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);
        $userId = request()->user()->id;
        $document->forceFill(['updated_by_id' => $userId])->saveQuietly();
        // El documento y sus archivos vinculados son el mismo elemento lógico:
        // van juntos a la papelera para no obligar a borrar dos veces.
        $document->files()->update(['updated_by_id' => $userId]);
        $document->files()->delete();
        $document->delete();

        return back()->with('success', 'Documento enviado a la papelera.');
    }

    public function restore(string $document): RedirectResponse
    {
        $documentModel = Document::withTrashed()->findOrFail($document);
        $this->authorize('restore', $documentModel);
        $documentModel->forceFill(['updated_by_id' => request()->user()->id])->restore();
        $documentModel->files()->withTrashed()->restore();

        return back()->with('success', 'Documento restaurado.');
    }

    public function forceDestroy(string $document): RedirectResponse
    {
        $documentModel = Document::withTrashed()->findOrFail($document);
        $this->authorize('forceDelete', $documentModel);
        // Purga también los binarios vinculados (disco + filas) para no dejar
        // huérfanos. Las versiones históricas se conservan como registro.
        $disk = config('filesystems.default');
        foreach ($documentModel->files()->withTrashed()->get() as $file) {
            Storage::disk($disk)->delete($file->storage_path);
            $file->forceDelete();
        }
        $documentModel->forceDelete();

        return back()->with('success', 'Documento eliminado definitivamente.');
    }

    public function exportPdf(Document $document, DocumentPdfExporter $exporter, AuditLogger $auditLogger): BinaryFileResponse|\Illuminate\Http\Response
    {
        $this->authorize('view', $document);

        try {
            $path = $exporter->export($document);

            $auditLogger->log('document.export_pdf', $document, [
                'title' => $document->title,
            ]);

            return response()->download(
                $path,
                Str::slug($document->title).'.pdf',
                ['Content-Type' => 'application/pdf'],
            )->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            Log::error('PDF export failed.', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);

            if ($this->expectsJson()) {
                return response()->json(['message' => 'No se pudo generar el PDF. Verifica que Node.js y Puppeteer estén instalados.'], 500);
            }

            return back()->withErrors([
                'export' => 'No se pudo generar el PDF. Verifica que Node.js y Puppeteer estén instalados.',
            ]);
        }
    }

    public function exportDocx(Document $document, AuditLogger $auditLogger, DocumentImageService $images): BinaryFileResponse|\Illuminate\Http\Response
    {
        $this->authorize('view', $document);

        try {
            // Fidelidad primero: si el documento tiene binario (versión guardada
            // o archivo Word vinculado), se descarga TAL CUAL. Solo los documentos
            // creados en el editor (sin archivo) se regeneran desde el HTML.
            $disk = config('filesystems.default');
            $binaryPath = $this->currentBinaryPath($document);

            if ($binaryPath !== null && Storage::disk($disk)->exists($binaryPath)) {
                $auditLogger->log('document.export_docx', $document, [
                    'title' => $document->title,
                    'source' => 'binary',
                ]);

                return response()->download(
                    Storage::disk($disk)->path($binaryPath),
                    Str::slug($document->title).'.docx',
                );
            }

            $phpWord = new PhpWord();
            $section = $phpWord->addSection();
            $section->addTitle($document->title, 1);
            $html = $images->inlineImagesForExport($document->content ?: '<p></p>');
            Html::addHtml($section, $html, true, true);

            $directory = storage_path('app/temp/docx');
            \Illuminate\Support\Facades\File::ensureDirectoryExists($directory);
            $path = $directory.DIRECTORY_SEPARATOR.'document-'.$document->id.'-'.time().'.docx';
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);

            $auditLogger->log('document.export_docx', $document, ['title' => $document->title]);

            return response()->download($path, Str::slug($document->title).'.docx')->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            Log::error('DOCX export failed.', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);

            if ($this->expectsJson()) {
                return response()->json(['message' => 'No se pudo generar el archivo Word.'], 500);
            }

            return back()->withErrors(['export' => 'No se pudo generar el archivo Word.']);
        }
    }

    /**
     * Ruta binaria vigente para descargar/servir: la última versión guardada o,
     * si aún no hay versiones, el archivo `.docx/.doc` vinculado al documento.
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
     * Marca la primera edición real para ocultar el aviso de conversión
     * en futuras aperturas.
     */
    private function markFirstEdit(Document $document, ?int $userId): void
    {
        if ($document->isImportedFromWord() && $document->first_edited_at === null) {
            $document->forceFill([
                'first_edited_at' => now(),
                'updated_by_id' => $userId,
            ])->save();
        }
    }
}
