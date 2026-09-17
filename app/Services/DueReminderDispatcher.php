<?php

namespace App\Services;

use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Genera las notificaciones de plataforma (+ Telegram opcional) para los
 * recordatorios vencidos.
 *
 * Se usa desde dos sitios:
 *  - Comando `reminders:dispatch` (scheduler/cron, con Telegram).
 *  - Peticiones web (HandleInertiaRequests, sin Telegram para no frenar la página).
 *
 * Es idempotente: usa UPDATE atómico con `whereNull('notified_at')` para que
 * dos procesos (web + scheduler) no creen la notificación dos veces.
 */
class DueReminderDispatcher
{
    /**
     * @param  User|null  $scopeUser  Si se indica, solo despacha sus recordatorios.
     * @param  bool  $withTelegram  Enviar Telegram cuando haya chat_id.
     */
    public static function run(?User $scopeUser = null, bool $withTelegram = true): int
    {
        // Hora OFICIAL (internet, America/Guayaquil): el disparo no depende
        // del reloj del equipo donde corre el servidor.
        $now = OfficialTime::now();
        $query = Reminder::query()
            ->with(['assignee', 'creator', 'recipients'])
            ->where('status', Reminder::STATUS_PENDING)
            ->where(fn ($q) => $q
                ->where('notify_at', '<=', $now)
                ->orWhere(fn ($q2) => $q2->whereNull('notify_at')->where('scheduled_at', '<=', $now)))
            ->orderBy('scheduled_at')
            ->limit(50);

        if ($withTelegram) {
            // Scheduler: pendientes de aviso O pendientes de Telegram
            // (el aviso web previo deja telegram_sent=false).
            $query->where(fn ($q) => $q->whereNull('notified_at')->orWhere('telegram_sent', false));
        } else {
            // Web: solo los que aún no tienen aviso de plataforma.
            $query->whereNull('notified_at');
        }

        if ($scopeUser) {
            $id = $scopeUser->getKey();
            $query->where(fn ($q) => $q
                ->where('assigned_to', $id)
                ->orWhereHas('recipients', fn ($r) => $r->where('users.id', $id)));
        }

        $due = $query->get();
        $sent = 0;

        foreach ($due as $reminder) {
            try {
                // Si no tiene notify_at (filas viejas), el aviso coincide con la fecha.
                if ($reminder->notify_at === null) {
                    $reminder->notify_at = $reminder->scheduled_at;
                }
                $late = $reminder->scheduled_at->lt($now->copy()->subMinute());

                // Todos los destinatarios (el calendario es global; el aviso es multi-usuario).
                // El $scopeUser solo elige QUÉ recordatorios revisar, pero el aviso
                // se genera para todos a la vez (el primero que entra lo dispara).
                $targets = $reminder->notificationTargets();
                if ($targets->isEmpty()) {
                    continue;
                }

                // 1) Aviso de plataforma (una sola vez por recordatorio, a cada destinatario).
                if ($reminder->notified_at === null) {
                    $claimed = Reminder::query()
                        ->whereKey($reminder->getKey())
                        ->whereNull('notified_at')
                        ->update(['notified_at' => $now->copy()]);

                    if ($claimed > 0) {
                        foreach ($targets as $target) {
                            $target->notify(new ReminderDue($reminder, $late));
                        }
                        $reminder->notified_at = $now->copy();
                        $sent++;
                    }
                }

                // 2) Telegram a cada destinatario con chat registrado.
                if ($withTelegram && ! $reminder->telegram_sent) {
                    $text = TelegramNotifier::reminderText($reminder, $late);
                    $anySent = false;
                    foreach ($targets as $target) {
                        if (empty($target->telegram_chat_id)) {
                            continue;
                        }
                        if (TelegramNotifier::send($target->telegram_chat_id, $text)) {
                            $anySent = true;
                        }
                    }

                    $reminder->forceFill(['telegram_sent' => $anySent])->save();
                }
            } catch (Throwable $exception) {
                Log::warning('DueReminderDispatcher failed.', [
                    'reminder_id' => $reminder->getKey(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
