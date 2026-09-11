<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DocumentVersionUploader
{
    public function __construct(
        private readonly WordDocumentImporter $importer,
    ) {}

    /**
     * Guarda el binario como nueva versión del documento, sincroniza el archivo
     * vinculado (para la descarga "Original") y regenera el HTML de la
     * plataforma para que la previsualización refleje el contenido guardado.
     *
     * Concurrencia: las escrituras de versión se serializan con un `SELECT ...
     * FOR UPDATE` sobre la fila del documento, de modo que si dos usuarios
     * suben versiones al mismo tiempo la segunda espera y el número de versión
     * se calcula sobre el estado final (sin duplicados ni saltos).
     *
     * También se usa con un `.docx` subido manualmente desde el panel: así el
     * nombre del archivo puede cambiar sin crear un documento nuevo.
     */
    public function upload(
        Document $document,
        UploadedFile $uploadedFile,
        int $userId,
        string $changeSummary,
        string $source = 'upload',
        bool $updateLinkedName = false,
    ): DocumentVersion {
        $buffer = (string) $uploadedFile->getContent();
        $extension = strtolower($uploadedFile->getClientOriginalExtension() ?: 'docx');

        abort_if(strlen($buffer) > (int) config('addin.max_file_size', 20 * 1024 * 1024), 413, 'Documento demasiado grande.');
        abort_if($extension === 'docx' && ! str_starts_with($buffer, "PK\x03\x04"), 422, 'El archivo recibido no es un DOCX válido.');

        $version = DB::transaction(function () use ($document, $uploadedFile, $buffer, $extension, $userId, $changeSummary, $source, $updateLinkedName): DocumentVersion {
            $lockedDocument = Document::query()->lockForUpdate()->find($document->getKey());
            abort_unless($lockedDocument !== null, 404, 'El documento no existe.');

            $disk = config('filesystems.default');
            $newPath = 'documents/'.$lockedDocument->getKey().'/versions/'.Str::uuid().'.'.$extension;
            Storage::disk($disk)->put($newPath, $buffer);

            $versionNumber = 'v'.($lockedDocument->versions()->count() + 1);

            $version = DocumentVersion::create([
                'document_id' => $lockedDocument->getKey(),
                'user_id' => $userId,
                'file_path' => $newPath,
                'file_size' => strlen($buffer),
                'version_number' => $versionNumber,
                'change_summary' => $changeSummary,
            ]);

            $lockedDocument->forceFill([
                'current_version_id' => $version->id,
                'updated_by_id' => $userId,
            ])->saveQuietly();

            $this->syncLinkedFile($lockedDocument, $newPath, $buffer, $userId, $source, $updateLinkedName ? $uploadedFile->getClientOriginalName() : null);

            return $version;
        });

        // Regenera el HTML del documento para que la plataforma (Explorador,
        // vista previa) refleje el contenido guardado desde Word o el subido
        // manualmente desde el panel. Se ejecuta fuera de la transacción para
        // no mantener el bloqueo de fila mientras se procesa el `.docx`.
        try {
            $html = $this->importer->toHtml($uploadedFile->getRealPath(), $extension);
            $document->forceFill([
                'content' => $html,
                'updated_by_id' => $userId,
            ])->saveQuietly();
        } catch (Throwable $exception) {
            Log::warning('No se pudo actualizar el HTML del documento tras guardar una versión.', [
                'document_id' => $document->id,
                'version_number' => $version->version_number,
                'error' => $exception->getMessage(),
            ]);
        }

        return $version;
    }

    /**
     * Mantiene sincronizado el archivo vinculado (y su historial FileVersion)
     * con la última versión guardada, para que la descarga "Original" del
     * Explorador apunte siempre al binario más reciente.
     */
    private function syncLinkedFile(Document $document, string $newPath, string $buffer, int $userId, string $source, ?string $originalName): void
    {
        $linked = $document->wordFile();

        if (! $linked instanceof File) {
            return;
        }

        $nextFileVersion = ($linked->versions()->max('version') ?? 0) + 1;

        $linked->forceFill([
            'storage_path' => $newPath,
            'file_size' => strlen($buffer),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'updated_by_id' => $userId,
            ...($originalName !== null ? ['original_name' => $originalName] : []),
        ])->saveQuietly();

        $linked->versions()->create([
            'version' => $nextFileVersion,
            'storage_path' => $newPath,
            'file_size' => strlen($buffer),
            'user_id' => $userId,
            'source' => $source,
        ]);
    }
}