<?php

namespace App\Notifications;

use App\Models\Reminder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReminderDue extends Notification
{
    use Queueable;

    public function __construct(public Reminder $reminder, public bool $late = false) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $reminder = $this->reminder;
        $before = (int) ($reminder->remind_before_minutes ?? 0);
        $pre = $before > 0 && $reminder->effectiveNotifyAt()->lt($reminder->scheduled_at);
        $names = $reminder->notificationTargets()->map(fn ($u) => $u->name)->filter()->values();

        return [
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'description' => $reminder->description,
            'scheduled_at' => $reminder->scheduled_at->toISOString(),
            'notify_at' => $reminder->effectiveNotifyAt()->toISOString(),
            'remind_before_minutes' => $before,
            'kind' => $pre ? 'pre' : ($this->late ? 'late' : 'due'),
            'late' => $this->late,
            'assignee_name' => $names->first() ?? $reminder->creator?->name,
            'recipients' => $names->take(5)->values()->all(),
            'recipients_count' => $names->count(),
            'message' => ($this->late ? 'Vencido: ' : ($pre ? 'En '.$before.' min: ' : 'Recordatorio: ')).$reminder->title,
        ];
    }
}
