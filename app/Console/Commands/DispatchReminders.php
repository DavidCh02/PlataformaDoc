<?php

namespace App\Console\Commands;

use App\Services\DueReminderDispatcher;
use App\Services\OfficialTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DispatchReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Envía los recordatorios vencidos (plataforma + Telegram).';

    public function handle(): int
    {
        $sent = DueReminderDispatcher::run(null, true);

        // Queda registrado para la consola secreta de diagnóstico (lab).
        Cache::put('reminders:last_dispatch_at', OfficialTime::now()->toDateTimeString().' ('.$sent.' enviados)', now()->addWeek());

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }
}
