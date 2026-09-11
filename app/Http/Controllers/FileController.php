<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadFileRequest;
use App\Models\Document;
use App\Models\File;
use App\Services\AuditLogger;
use App\Services\WordDocumentLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileController extends Controller
{
    public function store(UploadFileRequest $request, WordDocumentLinker $linker): RedirectResponse
    {
        $this->authorize('upload', File::class);

        $uploadedFile = $request->file('file');
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        $disk = config('filesystems.default');
        $storedName = Str::uuid().'.'.$extension;
        $storagePath = $uploadedFile->storeAs(
            'files/'.$request->user()->id,
            $storedName,
            $disk,
        );

        $file = File::create([
            'name' => pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $uploadedFile->getClientOriginalName(),
            'mime_type' => $uploadedFile->getMimeType() ?? 'application/octet-stream',
            'storage_path' => $storagePath,
            'file_size' => $uploadedFile->getSize(),
            'folder_id' => $request->validated('folder_id'),
            'user_id' => $request->user()->id,
        ]);

        // Un .docx subido desde el Explorador se convierte al instante en un
        // documento editable por el Add-in (acciones Modificar / Subir versión /
        // Historial), conservando el binario original como v1. El resto de
        // archivos siguen entrando como archivos sueltos.
        if ($extension === 'docx' && $request->user()->can('docs.create')) {
            $linker->link($file, $request->user());
        }

        return back()->with('success', $extension === 'docx' && $file->document_id
            ? 'Documento de Word subido. Ya puedes editarlo en Word (Modificar), subir versiones o ver su historial.'
            : 'Archivo subido correctamente.');
    }

    public function download(File $file, AuditLogger $auditLogger): StreamedResponse
    {
        $this->authorize('download', $file);

        $disk = config('filesystems.default');
        abort_unless(Storage::disk($disk)->exists($file->storage_path), 404);

        $auditLogger->log('file.download', $file, [
            'original_name' => $file->original_name,
        ]);

        return Storage::disk($disk)->download($file->storage_path, $file->original_name);
    }

    public function blob(File $file, AuditLogger $auditLogger): BinaryFileResponse
    {
        $this->authorize('view', $file);

        $disk = config('filesystems.default');
        abort_unless(Storage::disk($disk)->exists($file->storage_path), 404);

        $auditLogger->log('file.view', $file, [
            'original_name' => $file->original_name,
        ]);

        return response()->file(Storage::disk($disk)->path($file->storage_path), [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(File $file): RedirectResponse
    {
        $this->authorize('delete', $file);
        $userId = request()->user()->id;
        $file->forceFill(['updated_by_id' => $userId])->saveQuietly();
        // Si el archivo es la cara binaria de un documento, el documento va
        // con él a la papelera (mismo elemento lógico).
        if ($file->document_id) {
            Document::query()->whereKey($file->document_id)->update(['updated_by_id' => $userId]);
            Document::query()->whereKey($file->document_id)->delete();
        }
        $file->delete();

        return back()->with('success', 'Archivo enviado a la papelera.');
    }

    public function restore(string $file): RedirectResponse
    {
        $fileModel = File::withTrashed()->findOrFail($file);
        $this->authorize('restore', $fileModel);
        $fileModel->forceFill(['updated_by_id' => request()->user()->id])->restore();
        if ($fileModel->document_id) {
            Document::withTrashed()->whereKey($fileModel->document_id)->restore();
        }

        return back()->with('success', 'Archivo restaurado.');
    }

    public function forceDestroy(string $file): RedirectResponse
    {
        $fileModel = File::withTrashed()->findOrFail($file);
        $this->authorize('forceDelete', $fileModel);

        Storage::disk(config('filesystems.default'))->delete($fileModel->storage_path);
        if ($fileModel->document_id) {
            Document::withTrashed()->whereKey($fileModel->document_id)->forceDelete();
        }
        $fileModel->forceDelete();

        return back()->with('success', 'Archivo eliminado definitivamente.');
    }
}
