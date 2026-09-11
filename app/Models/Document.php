<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'content',
        'folder_id',
        'user_id',
        'updated_by_id',
        'imported_from',
        'platform_created',
        'first_edited_at',
        'is_locked',
        'locked_by_id',
        'locked_at',
        'editing_cancelled_at',
        'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'first_edited_at' => 'datetime',
            'platform_created' => 'boolean',
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
            'editing_cancelled_at' => 'datetime',
        ];
    }

    public function isImportedFromWord(): bool
    {
        return $this->imported_from !== null;
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * Imágenes incrustadas en el contenido (solo viven en el documento).
     */
    public function images(): HasMany
    {
        return $this->hasMany(DocumentImage::class);
    }

    /**
     * Historial de versiones del documento (guardados del Add-in de Word).
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('id');
    }

    /**
     * Versión activa (la más reciente guardada desde el Add-in).
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }

    /**
     * Archivo `.docx`/.doc vinculado al documento (si existe). Es el binario
     * base que el Add-in descarga y sobre el que sube nuevas versiones.
     */
    public function wordFile(): ?File
    {
        return $this->files()
            ->orderByDesc('id')
            ->get()
            ->first(fn (File $file) => in_array($file->extension(), ['docx', 'doc'], true));
    }
}
