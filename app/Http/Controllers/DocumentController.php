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

        return Inertia::render('Editor', [
            'document' => $document,
            'canEdit' => request()->user()->can('docs.edit_realtime'),
        ]);
    }

    public function update(UpdateDocumentRequest $request, Document $document): JsonResponse
    {
        $this->authorize('update', $document);
        $document->update(array_merge($request->validated(), [
            'updated_by_id' => $request->user()->id,
        ]));

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

        $document = DB::transaction(function () use ($file, $importer) {
            $lockedFile = File::query()->with('document')->lockForUpdate()->findOrFail($file->id);

            if ($lockedFile->document_id && $lockedFile->document) return $lockedFile->document;

            $disk = config('filesystems.default');
            $sourcePath = \Illuminate\Support\Facades\Storage::disk($disk)->path($lockedFile->storage_path);
            $content = $importer->toHtml($sourcePath, pathinfo($lockedFile->original_name, PATHINFO_EXTENSION));
            $document = Document::create([
                'title' => pathinfo($lockedFile->original_name, PATHINFO_FILENAME),
                'content' => $content,
                'folder_id' => $lockedFile->folder_id,
                'user_id' => request()->user()->id,
            ]);
            $lockedFile->update([
                'document_id' => $document->id,
                'updated_by_id' => request()->user()->id,
            ]);

            return $document;
        });

        return redirect()->route('documents.edit', $document);
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
}
