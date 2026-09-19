<?php

namespace App\Services;

use App\Models\Reminder;
use App\Notifications\ReminderDue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envío forzado de un recordatorio para depuración ("adelantar" el aviso
 * aunque todavía no sea la hora). Devuelve una traza detallada por
 * destinatario para mostrar en pantalla qué pasó y qué falló.
 */
class ReminderDebugSender
{
    /**
     * @return array{ok:bool, reminder_id:int, targets:array, platform:array, hint:string}
     */
    public static function fire(Reminder $reminder, bool $withPlatform = true): array
    {
        $reminder->loadMissing(['assignee', 'creator', 'recipients']);
        $trace = [
            'ok' => false,
            'reminder_id' => $reminder->getKey(),
            'title' => $reminder->title,
            'scheduled_at' => $reminder->scheduled_at?->toISOString(),
            'notify_at' => $reminder->effectiveNotifyAt()->toISOString(),
            'telegram_configured' => TelegramNotifier::enabled(),
            'targets' => [],
            'platform' => ['sent' => 0, 'skipped' => null],
            'hint' => '',
        ];

        $late = $reminder->scheduled_at->lt(OfficialTime::now()->subMinute());
        $targets = $reminder->notificationTargets();

        if ($targets->isEmpty()) {
            $trace['hint'] = 'El recordatorio no tiene destinatarios (ni recipients, ni assigned_to, ni creador). Edítalo y marca al menos un usuario a notificar.';

            return $trace;
        }

        // 1) Aviso de plataforma (solo si aún no se generó, para no duplicar).
        if ($withPlatform && $reminder->notified_at === null) {
            try {
                foreach ($targets as $target) {
                    $target->notify(new ReminderDue($reminder, $late));
                }
                $reminder->forceFill(['notified_at' => OfficialTime::now()])->save();
                $trace['platform']['sent'] = $targets->count();
            } catch (Throwable $e) {
                $trace['platform']['error'] = self::errorInfo($e);
                Log::warning('ReminderDebugSender platform failed.', ['reminder_id' => $reminder->getKey(), 'error' => $e->getMessage()]);
            }
        } else {
            $trace['platform']['skipped'] = $reminder->notified_at !== null
                ? 'Ya tenía aviso de plataforma (notified_at='.$reminder->notified_at->toDateTimeString().'); no se duplicó.'
                : 'Omitido por opción.';
        }

        // 2) Telegram a cada destinatario, con resultado individual.
        if (! TelegramNotifier::enabled()) {
            $trace['hint'] = 'TELEGRAM_BOT_TOKEN no está configurado en el servidor (.env). Sin eso ningún envío a Telegram puede funcionar.';
        }

        $text = TelegramNotifier::reminderText($reminder, $late);
        $trace['message_preview'] = $text;

        $anySent = false;
        foreach ($targets as $target) {
            $row = [
                'user_id' => $target->getKey(),
                'name' => $target->name,
                'chat_id' => $target->telegram_chat_id ?: null,
                'sent' => false,
                'error' => null,
            ];
            try {
                if (empty($target->telegram_chat_id)) {
                    $row['error'] = 'Sin chat vinculado: el usuario debe abrir su perfil → Telegram → "Vincular con Telegram" y pulsar /start en el bot.';
                } else {
                    $row['sent'] = TelegramNotifier::send($target->telegram_chat_id, "🧪 <b>[PRUEBA]</b>\n".$text);
                    if ($row['sent']) {
                        $anySent = true;
                    } else {
                        $row['error'] = 'La Bot API no aceptó el envío (token inválido, bot bloqueado por el usuario o el chat no hizo /start). Revisa el log laravel.log.';
                    }
                }
            } catch (Throwable $e) {
                $row['error'] = $e->getMessage().' ('.class_basename($e).' '.$e->getFile().':'.$e->getLine().')';
                Log::warning('ReminderDebugSender telegram failed.', ['reminder_id' => $reminder->getKey(), 'user_id' => $target->getKey(), 'error' => $e->getMessage()]);
            }
            $trace['targets'][] = $row;
        }

        // No marcamos telegram_sent como definitivo en modo prueba para no
        // "quemar" el aviso real: solo lo marcamos si efectivamente llegó a todos.
        $missing = collect($trace['targets'])->where('sent', false)->count();
        if ($anySent && $missing === 0) {
            $reminder->forceFill(['telegram_sent' => true])->save();
            $trace['hint'] = 'Llegó a todos los destinatarios con chat vinculado. El aviso quedó marcado como enviado.';
        } elseif ($anySent) {
            $trace['hint'] = 'Llegó solo a algunos. A los demás les falta vincular el chat o bloquearon al bot.';
        } elseif ($trace['hint'] === '') {
            $trace['hint'] = 'No se pudo entregar a nadie. Revisa la columna error de cada destinatario.';
        }

        $trace['ok'] = $anySent;

        return $trace;
    }

    public static function errorInfo(Throwable $e): string
    {
        return $e->getMessage().' ('.class_basename($e).' '.$e->getFile().':'.$e->getLine().')';
    }
}
