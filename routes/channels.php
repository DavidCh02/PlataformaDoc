<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Document;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

$canAccessDocument = function ($user, $documentId): bool {
    $document = Document::find($documentId);

    return $document !== null
        && ($user->can('files.view') || $user->can('docs.edit_realtime'));
};

Broadcast::channel('document.{documentId}', $canAccessDocument);
Broadcast::channel('presence-document.{documentId}', function ($user, $documentId) use ($canAccessDocument): array|bool {
    if (! $canAccessDocument($user, $documentId)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
