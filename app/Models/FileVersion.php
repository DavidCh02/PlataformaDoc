<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileVersion extends Model
{
    protected $fillable = [
        'file_id',
        'version',
        'storage_path',
        'file_size',
        'user_id',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
