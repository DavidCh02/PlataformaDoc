<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Destinatarios del aviso (plataforma + Telegram).
     * El calendario es global: todos ven el mismo recordatorio;
     * esta tabla solo define A QUIÉNES les llega la notificación.
     */
    public function up(): void
    {
        Schema::create('reminder_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->constrained('reminders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['reminder_id', 'user_id']);
        });

        // Relleno: el asignado actual pasa a ser destinatario.
        $rows = DB::table('reminders')->whereNotNull('assigned_to')->get(['id', 'assigned_to']);
        foreach ($rows as $row) {
            DB::table('reminder_user')->updateOrInsert(
                ['reminder_id' => $row->id, 'user_id' => $row->assigned_to],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_user');
    }
};
