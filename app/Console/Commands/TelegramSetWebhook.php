<?php

namespace App\Console\Commands;

use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:webhook {--remove : Elimina el webhook en lugar de configurarlo}';

    protected $description = 'Verifica el bot y configura (o elimina) su webhook hacia esta app.';

    public function handle(): int
    {
        $bot = TelegramNotifier::checkBot();
        if (! $bot) {
            $this->error('No se pudo contactar a Telegram. Revisa TELEGRAM_BOT_TOKEN en tu .env.');

            return self::FAILURE;
        }

        $this->info("Bot OK: @{$bot['username']} ({$bot['first_name']}).");

        if ($this->option('remove')) {
            TelegramNotifier::api('deleteWebhook', ['drop_pending_updates' => true]);
            $this->info('Webhook eliminado.');

            return self::SUCCESS;
        }

        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '') {
            $this->error('Falta TELEGRAM_WEBHOOK_SECRET en tu .env (cualquier texto largo aleatorio).');

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/')."/telegram/webhook/{$secret}";
        if (! str_starts_with($url, 'https://')) {
            $this->warn("OJO: tu APP_URL es {$url}. Telegram exige una URL pública HTTPS para el webhook.");
            $this->warn('En local puedes probar el envío con el botón "Enviar prueba", y exponer tu PC con ngrok/cloudflared para el auto-vínculo.');
            if (! $this->confirm('¿Configurar el webhook de todos modos?', false)) {
                return self::FAILURE;
            }
        }

        $result = TelegramNotifier::api('setWebhook', ['url' => $url, 'drop_pending_updates' => true]);
        if (! ($result['ok'] ?? false)) {
            $this->error('Telegram rechazó el webhook: '.json_encode($result));

            return self::FAILURE;
        }

        $this->info("Webhook configurado: {$url}");
        $this->info('Ahora cada usuario vincula su chat desde Mi perfil → Telegram → "Vincular con Telegram".');

        return self::SUCCESS;
    }
}
