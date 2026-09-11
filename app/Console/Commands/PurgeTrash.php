<?php

namespace App\Console\Commands;

use App\Services\TrashPurger;
use Illuminate\Console\Command;

class PurgeTrash extends Command
{
    protected $signature = 'trash:purge';

    protected $description = 'Elimina definitivamente los elementos con más de 7 días en la papelera.';

    public function handle(TrashPurger $purger): int
    {
        $counts = $purger->purgeExpired();
        $total = array_sum($counts);
        $this->info("Papelera purgada: {$total} elementos (archivos: {$counts['files']}, documentos: {$counts['documents']}, carpetas: {$counts['folders']}).");

        return self::SUCCESS;
    }
}
