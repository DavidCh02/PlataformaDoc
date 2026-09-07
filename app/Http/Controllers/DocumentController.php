<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportWordRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\SyncDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Events\DocumentUpdated;
use App\Models\Document;
use App\Models\File;
use App\Services\WordDocumentImporter;
use App\Services\DocumentPdfExporter;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
    public function create(StoreDocumentRequest $request): Response
    {
        $this->authorize('create', Document::class);

        $document = Document::create([
            'title' => $request->validated('title'),
            'folder_id' => $request->validated('folder_id'),
            'user_id' => $request->user()->id,
            'content' => '<p></p>',
        ]);

        return Inertia::render('Editor', [
            'document' => $document,
            'canEdit' => true,
        ]);
    }

    public function edit(Document $document): Response
    {
        $this->authorize('view', $document);

        $siblings = Document::query()
            ->where('folder_id', $document->folder_id)
            ->orderBy('title')
            ->get(['id', 'title', 'folder_id']);

        return Inertia::render('Editor', [
            'document' => $document,
            'canEdit' => request()->user()->can('docs.edit_realtime'),
            'siblings' => $siblings,
        ]);
    }

    public function update(UpdateDocumentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);
        $userId = $request->user()->id;
        $document->update(array_merge($request->validated(), [
            'updated_by_id' => $userId,
        ]));
        $this->markFirstEdit($document, $userId);

        return response()->json([
            'saved' => true,
            'document' => $document->only(['id', 'title', 'updated_at']),
        ]);
    }

    public function sync(SyncDocumentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);

        $content = $request->validated('content');
        if ($content !== null) {
            $document->update([
                'content' => $content,
                'updated_by_id' => $request->user()->id,
            ]);
            $this->markFirstEdit($document, $request->user()->id);
        }

        event(new DocumentUpdated(
            documentId: $document->id,
            userId: $request->user()->id,
            delta: $request->validated('delta', ''),
            content: $content,
        ));

        return response()->json(['broadcast' => true]);
    }

    public function importWord(ImportWordRequest $request, WordDocumentImporter $importer): RedirectResponse|JsonResponse
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

    public function destroy(Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);
        $document->forceFill(['updated_by_id' => request()->user()->id])->saveQuietly();
        $document->delete();

        return back()->with('success', 'Documento enviado a la papelera.');
    }

    public function restore(string $document): RedirectResponse
    {
        $documentModel = Document::withTrashed()->findOrFail($document);
        $this->authorize('restore', $documentModel);
        $documentModel->forceFill(['updated_by_id' => request()->user()->id])->restore();

        return back()->with('success', 'Documento restaurado.');
    }

    public function forceDestroy(string $document): RedirectResponse
    {
        $documentModel = Document::withTrashed()->findOrFail($document);
        $this->authorize('forceDelete', $documentModel);
        $documentModel->forceDelete();

        return back()->with('success', 'Documento eliminado definitivamente.');
    }

    public function exportPdf(Document $document, DocumentPdfExporter $exporter, AuditLogger $auditLogger): BinaryFileResponse|RedirectResponse
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

            return back()->withErrors([
                'export' => $exception->getMessage(),
            ]);
        }
    }

    public function exportDocx(Document $document, AuditLogger $auditLogger): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $document);

        try {
            $phpWord = new PhpWord();
            $section = $phpWord->addSection();
            $section->addTitle($document->title, 1);
            Html::addHtml($section, $document->content ?: '<p></p>', false, false);

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

            return back()->withErrors(['export' => 'No se pudo generar el archivo Word.']);
        }
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
