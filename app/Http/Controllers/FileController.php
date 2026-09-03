<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadFileRequest;
use App\Models\File;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileController extends Controller
{
    public function store(UploadFileRequest $request): RedirectResponse
    {
        $this->authorize('upload', File::class);

        $uploadedFile = $request->file('file');
        $disk = config('filesystems.default');
        $storedName = Str::uuid().'.'.$uploadedFile->getClientOriginalExtension();
        $storagePath = $uploadedFile->storeAs(
            'files/'.$request->user()->id,
            $storedName,
            $disk,
        );

        File::create([
            'name' => pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $uploadedFile->getClientOriginalName(),
            'mime_type' => $uploadedFile->getMimeType() ?? 'application/octet-stream',
            'storage_path' => $storagePath,
            'file_size' => $uploadedFile->getSize(),
            'folder_id' => $request->validated('folder_id'),
            'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Archivo subido correctamente.');
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
        $file->forceFill(['updated_by_id' => request()->user()->id])->saveQuietly();
        $file->delete();

        return back()->with('success', 'Archivo enviado a la papelera.');
    }

    public function restore(string $file): RedirectResponse
    {
        $fileModel = File::withTrashed()->findOrFail($file);
        $this->authorize('restore', $fileModel);
        $fileModel->forceFill(['updated_by_id' => request()->user()->id])->restore();

        return back()->with('success', 'Archivo restaurado.');
    }

    public function forceDestroy(string $file): RedirectResponse
    {
        $fileModel = File::withTrashed()->findOrFail($file);
        $this->authorize('forceDelete', $fileModel);

        Storage::disk(config('filesystems.default'))->delete($fileModel->storage_path);
        $fileModel->forceDelete();

        return back()->with('success', 'Archivo eliminado definitivamente.');
    }
}
