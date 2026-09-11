<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Imagen incrustada en el contenido HTML de un documento (editor de la
 * plataforma). Intencionalmente SEPARADA de `files`: nunca aparece en el
 * explorador ni participa en versiones/vinculados/descargas.
 */
class DocumentImage extends Model
{
    protected $fillable = [
        'document_id',
        'user_id',
        'folder_id',
        'storage_path',
        'mime_type',
        'file_size',
        'name',
        'original_name',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
