<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentVersion;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Historial de versiones de los documentos editados con el Add-in de Word.
 * Responsabilidades:
 *  - Pantalla web "Historial de Versiones" (timeline estilo GitHub).
 *  - Comentarios/notas de la plataforma sobre una versión concreta.
 *  - Descarga de binarios históricos de cada versión.
 */
class DocumentHistoryController extends Controller
{
    public function show(Document $document): Response
    {
        $this->authorize('view', $document);

        $versions = $document->versions()
            ->with(['user:id,name,email', 'annotations.user:id,name,email'])
            ->get(['id', 'document_id', 'user_id', 'file_path', 'version_number', 'change_summary', 'file_size', 'created_at']);

        return Inertia::render('DocumentHistory', [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'folder_id' => $document->folder_id,
                'file_name' => $document->wordFile()?->original_name,
                'is_locked' => $document->is_locked,
                'locked_by' => $document->is_locked ? $document->lockedBy?->name : null,
                'locked_by_id' => $document->is_locked ? $document->locked_by_id : null,
            ],
            'current_version' => $document->currentVersion?->version_number,
            'versions' => $versions,
            'canDownload' => request()->user()->can('files.download'),
        ]);
    }

    /**
     * Deja una nota/comentario de la plataforma sobre una versión concreta.
     */
    public function storeAnnotation(
        Request $request,
        DocumentVersion $version,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->authorize('view', $version->document);

        $comment = trim((string) $request->input('comment', ''));
        abort_if($comment === '', 422, 'El comentario es obligatorio.');
        abort_if(mb_strlen($comment) > 2000, 422, 'El comentario no puede superar 2000 caracteres.');

        $annotation = DocumentAnnotation::create([
            'document_version_id' => $version->id,
            'user_id' => $request->user()->id,
            'comment' => $comment,
        ]);

        $auditLogger->log('document_version.annotation', $annotation, [
            'document_version_id' => $version->id,
        ]);

        return back()->with('success', 'Comentario agregado al historial.');
    }

    /**
     * Descarga el binario de una versión histórica concreta del documento.
     */
    public function download(DocumentVersion $version, AuditLogger $auditLogger): StreamedResponse
    {
        $this->authorize('view', $version->document);
        abort_unless(request()->user()->can('files.download'), 403, 'Sin permiso para descargar.');

        $disk = config('filesystems.default');
        abort_unless(Storage::disk($disk)->exists($version->file_path), 404, 'El archivo ya no está disponible.');

        $base = Str::slug($version->document->title) ?: 'documento';
        $extension = pathinfo($version->file_path, PATHINFO_EXTENSION) ?: 'docx';
        $name = $base.'.'.$version->version_number.'.'.$extension;

        $auditLogger->log('document_version.download', $version, [
            'version_number' => $version->version_number,
        ]);

        return Storage::disk($disk)->download($version->file_path, $name);
    }
}