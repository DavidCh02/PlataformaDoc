<?php

namespace App\Console\Commands;

use App\Services\DueReminderDispatcher;
use Illuminate\Console\Command;

class DispatchReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Envía los recordatorios vencidos (plataforma + Telegram).';

    public function handle(): int
    {
        $sent = DueReminderDispatcher::run(null, true);

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }
}
