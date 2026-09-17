<?php

namespace App\Services;

use App\Models\Reminder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramNotifier
{
    public static function enabled(): bool
    {
        return (string) config('services.telegram.bot_token') !== '';
    }

    public static function botUsername(): string
    {
        return ltrim((string) config('services.telegram.bot_username'), '@');
    }

    /** Enlace de vinculación para un usuario: t.me/Bot?start=TOKEN. */
    public static function linkFor(string $linkToken): ?string
    {
        $bot = self::botUsername();
        if ($bot === '' || $linkToken === '') {
            return null;
        }

        return "https://t.me/{$bot}?start={$linkToken}";
    }

    /** Llamada genérica a la Bot API. Devuelve el JSON decodificado o null. */
    public static function api(string $method, array $params = []): ?array
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return null;
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $params);
            if (! $response->successful()) {
                Log::warning('Telegram API call failed.', ['method' => $method, 'status' => $response->status()]);
            }

            return $response->json();
        } catch (Throwable $exception) {
            Log::warning('Telegram API call failed.', ['method' => $method, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    /** Verifica que el token del bot sea válido. Devuelve info del bot o null. */
    public static function checkBot(): ?array
    {
        $data = self::api('getMe');

        return ($data['ok'] ?? false) ? $data['result'] : null;
    }

    /**
     * Envía un mensaje vía Bot API. IMPORTANTE: los bots NO pueden escribir a
     * números de teléfono; el usuario debe iniciar el bot (/start) y registrar
     * su chat_id en su perfil.
     */
    public static function send(string $chatId, string $text): bool
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '' || trim($chatId) === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            return $response->successful() && (bool) $response->json('ok', false);
        } catch (Throwable $exception) {
            Log::warning('Telegram reminder failed.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    public static function reminderText(Reminder $reminder, bool $late): string
    {
        $tz = (string) config('app.timezone', 'America/Guayaquil');
        $before = (int) ($reminder->remind_before_minutes ?? 0);
        $headline = $late
            ? '⏰ <b>Recordatorio vencido</b>'
            : ($before > 0 ? '🔔 <b>Pre-aviso ('.$before.' min antes)</b>' : '⏰ <b>Recordatorio</b>');
        $names = $reminder->notificationTargets()->map(fn ($u) => $u->name)->filter()->values();
        $lines = [
            $headline,
            '<b>'.e($reminder->title).'</b>',
            'Fecha: '.$reminder->scheduled_at->copy()->setTimezone($tz)->format('d/m/Y H:i').' (hora Ecuador)',
        ];

        if ($names->count() > 1) {
            $lines[] = 'Para: '.e($names->take(5)->implode(', ').($names->count() > 5 ? '… +'.($names->count() - 5) : ''));
        }

        if (trim((string) $reminder->description) !== '') {
            $lines[] = e(mb_strimwidth((string) $reminder->description, 0, 300, '…'));
        }

        return implode("\n", $lines);
    }
}
