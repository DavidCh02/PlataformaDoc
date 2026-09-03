<?php

namespace App\Observers;

use App\Models\File;
use App\Services\AuditLogger;

class FileObserver
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function created(File $file): void
    {
        $this->auditLogger->log('file.upload', $file, [
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'file_size' => $file->file_size,
        ]);
    }

    public function deleted(File $file): void
    {
        $this->auditLogger->log('file.delete', $file, [
            'original_name' => $file->original_name,
        ]);
    }

    public function restored(File $file): void
    {
        $this->auditLogger->log('file.restore', $file, [
            'original_name' => $file->original_name,
        ]);
    }

    public function forceDeleted(File $file): void
    {
        $this->auditLogger->log('file.force_delete', $file, [
            'original_name' => $file->original_name,
        ]);
    }
}
