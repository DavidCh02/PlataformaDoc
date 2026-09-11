<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Convierte un `.docx`/`.doc` subido en un Documento editable por el Add-in de
 * Word conservando el binario original como versión inicial (v1). Es el único
 * punto de verdad compartido por `files.link-word` y la conversión automática
 * que hace el Explorador al subir un .docx desde el cuadro de subida.
 */
class WordDocumentLinker
{
    public function link(File $file, User $user): Document
    {
        return DB::transaction(function () use ($file, $user): Document {
            $lockedFile = File::query()->with('document')->lockForUpdate()->findOrFail($file->id);

            if ($lockedFile->document_id && $lockedFile->document) {
                return $lockedFile->document;
            }

            // Reutiliza un documento huérfano con el mismo nombre si existe y
            // no está vinculado a ningún archivo (evita filas duplicadas).
            $orphan = Document::query()
                ->where('title', pathinfo($lockedFile->original_name, PATHINFO_FILENAME))
                ->whereDoesntHave('files')
                ->orderBy('id')
                ->first();

            $document = $orphan ?? Document::create([
                'title' => pathinfo($lockedFile->original_name, PATHINFO_FILENAME),
                'content' => '<p></p>',
                'folder_id' => $lockedFile->folder_id,
                'user_id' => $user->id,
                'imported_from' => $lockedFile->original_name,
            ]);

            $lockedFile->forceFill(['document_id' => $document->id])->saveQuietly();

            // Solo si el documento aún no tiene una versión activa: no se pisa
            // el historial de un documento huérfano que ya haya sido editado.
            if (! $document->current_version_id) {
                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'user_id' => $user->id,
                    'file_path' => $lockedFile->storage_path,
                    'file_size' => $lockedFile->file_size,
                    'version_number' => 'v1',
                    'change_summary' => 'Versión inicial del archivo subido',
                ]);

                $document->forceFill([
                    'current_version_id' => $version->id,
                    'updated_by_id' => $user->id,
                ])->saveQuietly();
            }

            return $document;
        });
    }
}