<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class Reminder extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';

    protected $fillable = [
        'title',
        'description',
        'scheduled_at',
        'remind_before_minutes',
        'notify_at',
        'assigned_to',
        'created_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'notify_at' => 'datetime',
            'notified_at' => 'datetime',
            'telegram_sent' => 'boolean',
            'remind_before_minutes' => 'integer',
        ];
    }

    /** Instante efectivo de aviso (pre-aviso aplicado). */
    public function effectiveNotifyAt(): \Carbon\Carbon
    {
        if ($this->notify_at) {
            return $this->notify_at;
        }

        return $this->scheduled_at->copy()->subMinutes((int) ($this->remind_before_minutes ?? 0));
    }

    public function isPreReminder(): bool
    {
        return ((int) ($this->remind_before_minutes ?? 0)) > 0;
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Destinatarios del aviso (plataforma + Telegram).
     * El calendario es global para todos; esto solo define a quién notificar.
     */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'reminder_user')->withTimestamps();
    }

    /** Usuarios a los que hay que avisar (destinatarios o respaldo asignado/creador). */
    public function notificationTargets(): Collection
    {
        $recipients = $this->relationLoaded('recipients') ? $this->recipients : $this->recipients()->get(['users.id', 'users.name', 'users.telegram_chat_id']);

        if ($recipients->isNotEmpty()) {
            return $recipients->unique('id')->values();
        }

        return collect([$this->assignee ?? $this->creator])->filter()->values();
    }

    public function isRecipient(User $user): bool
    {
        if ((int) $this->assigned_to === (int) $user->getKey()) {
            return true;
        }

        return $this->recipients()->where('users.id', $user->getKey())->exists();
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->scheduled_at->isPast();
    }
}
