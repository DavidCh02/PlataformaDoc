<?php

namespace App\Observers;

use App\Models\Document;
use App\Services\AuditLogger;

class DocumentObserver
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function created(Document $document): void
    {
        $this->auditLogger->log('document.create', $document, [
            'title' => $document->title,
        ]);
    }

    public function updated(Document $document): void
    {
        if ($document->wasChanged(['title', 'content'])) {
            $this->auditLogger->log('document.update', $document, [
                'title' => $document->title,
                'changed' => array_keys($document->getChanges()),
            ]);
        }
    }

    public function deleted(Document $document): void
    {
        $this->auditLogger->log('document.delete', $document, [
            'title' => $document->title,
        ]);
    }

    public function restored(Document $document): void
    {
        $this->auditLogger->log('document.restore', $document, [
            'title' => $document->title,
        ]);
    }

    public function forceDeleted(Document $document): void
    {
        $this->auditLogger->log('document.force_delete', $document, [
            'title' => $document->title,
        ]);
    }
}
