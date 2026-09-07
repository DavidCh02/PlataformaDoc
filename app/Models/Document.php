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
        'first_edited_at',
    ];

    protected function casts(): array
    {
        return [
            'first_edited_at' => 'datetime',
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
}
