<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    /**
     * Webhook que Telegram llama en cada mensaje al bot.
     * URL: POST /telegram/webhook/{secret} (ver TELEGRAM_WEBHOOK_SECRET).
     *
     * - "/start TOKEN" -> vincula el chat_id al usuario dueño del TOKEN.
     * - "/stop" o "/unlink" -> desvincula este chat.
     * - Cualquier otro texto -> ayuda breve.
     */
    public function webhook(Request $request, string $secret): JsonResponse
    {
        $configured = (string) config('services.telegram.webhook_secret');
        if ($configured === '' || ! hash_equals($configured, $secret)) {
            abort(404);
        }

        $message = $request->input('message') ?? $request->input('edited_message');
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === null) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) $chatId;

        if (str_starts_with($text, '/start')) {
            $token = trim((string) preg_replace('/^\/start(@\S+)?\s*/', '', $text));
            if ($token === '') {
                TelegramNotifier::api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "👋 ¡Hola! Para vincular tus recordatorios de PlataformaDoc, abre el enlace de vinculación desde tu perfil (Mi perfil → Telegram) y vuelve a pulsar el botón.\n\nSi ya no quieres avisos, escribe /stop.",
                ]);

                return response()->json(['ok' => true]);
            }

            $user = User::query()->where('telegram_link_token', $token)->first();
            if (! $user) {
                TelegramNotifier::api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => '⚠️ Ese enlace de vinculación no es válido. Entra a PlataformaDoc → Mi perfil → Telegram y usa el botón "Vincular con Telegram" para generar uno nuevo.',
                ]);

                return response()->json(['ok' => true]);
            }

            $user->forceFill(['telegram_chat_id' => $chatId])->save();
            TelegramNotifier::api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✅ ¡Listo, {$user->name}! Este chat quedó vinculado a PlataformaDoc. A partir de ahora recibirás aquí tus recordatorios. 🕑\n\nPara desvincular, escribe /stop.",
            ]);

            return response()->json(['ok' => true]);
        }

        if (in_array(mb_strtolower($text), ['/stop', '/unlink'], true)) {
            User::query()->where('telegram_chat_id', $chatId)->update(['telegram_chat_id' => null]);
            TelegramNotifier::api('sendMessage', [
                'chat_id' => $chatId,
                'text' => '🔕 Chat desvinculado: ya no recibirás recordatorios aquí. Puedes volver a vincularlo cuando quieras desde tu perfil.',
            ]);

            return response()->json(['ok' => true]);
        }

        TelegramNotifier::api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "🤖 Soy el bot de avisos de PlataformaDoc.\n\n• Para vincular tus recordatorios usa el botón de tu perfil.\n• Para dejar de recibir avisos escribe /stop.",
        ]);

        return response()->json(['ok' => true]);
    }

    /** Envía un mensaje de prueba al chat vinculado del usuario autenticado. */
    public function test(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! TelegramNotifier::enabled()) {
            return back()->withErrors(['telegram_chat_id' => 'El bot no está configurado en el servidor (falta TELEGRAM_BOT_TOKEN).']);
        }

        if (! $user->telegramLinked()) {
            return back()->withErrors(['telegram_chat_id' => 'Aún no tienes ningún chat vinculado. Pulsa "Vincular con Telegram" primero.']);
        }

        $sent = TelegramNotifier::send(
            $user->telegram_chat_id,
            "✅ <b>Prueba de PlataformaDoc</b>\nTu chat está vinculado correctamente, {$user->name}. Aquí recibirás tus recordatorios. 🕑",
        );

        return $sent
            ? back()->with('success', 'Mensaje de prueba enviado a tu Telegram. 📩')
            : back()->withErrors(['telegram_chat_id' => 'No se pudo enviar. Abre el chat del bot y pulsa /start, luego reintenta.']);
    }

    /** Desvincula el chat del usuario autenticado. */
    public function unlink(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['telegram_chat_id' => null])->save();

        return back()->with('success', 'Chat de Telegram desvinculado.');
    }
}
