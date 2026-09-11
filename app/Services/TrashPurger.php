<?php

namespace App\Services;

use App\Models\Document;
use App\Models\File;
use App\Models\Folder;
use Illuminate\Support\Facades\Storage;

/**
 * Purga la papelera: elimina definitivamente los elementos enviados hace más
 * de X días (por defecto 7). Replica la lógica de los forceDestroy
 * (incluye binarios en disco) para no dejar huérfanos.
 */
class TrashPurger
{
    public function __construct(private int $retentionDays = 7) {}

    /**
     * @return array{files: int, documents: int, folders: int}
     */
    public function purgeExpired(): array
    {
        return $this->purgeOlderThan(now()->subDays($this->retentionDays));
    }

    /**
     * @return array{files: int, documents: int, folders: int}
     */
    public function purgeAll(): array
    {
        return $this->purgeOlderThan(now());
    }

    /**
     * @return array{files: int, documents: int, folders: int}
     */
    private function purgeOlderThan(\DateTimeInterface $cutoff): array
    {
        $counts = ['files' => 0, 'documents' => 0, 'folders' => 0];
        $disk = config('filesystems.default');

        File::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($files) use ($disk, &$counts) {
            foreach ($files as $file) {
                Storage::disk($disk)->delete($file->storage_path);
                $file->forceDelete();
                $counts['files']++;
            }
        });

        Document::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($documents) use ($disk, &$counts) {
            foreach ($documents as $document) {
                foreach ($document->files()->withTrashed()->get() as $file) {
                    Storage::disk($disk)->delete($file->storage_path);
                    $file->forceDelete();
                }
                $document->forceDelete();
                $counts['documents']++;
            }
        });

        Folder::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($folders) use (&$counts) {
            foreach ($folders as $folder) {
                $folder->forceDelete();
                $counts['folders']++;
            }
        });

        return $counts;
    }
}
