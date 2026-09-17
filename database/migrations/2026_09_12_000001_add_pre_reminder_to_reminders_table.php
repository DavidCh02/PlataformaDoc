<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pre-aviso ("recordar antes"): minutos de anticipación + instante
     * efectivo de aviso (notify_at = scheduled_at - antes).
     */
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->integer('remind_before_minutes')->default(0)->after('scheduled_at');
            $table->dateTime('notify_at')->nullable()->after('remind_before_minutes');
            $table->index(['status', 'notify_at']);
        });

        // Relleno: sin pre-aviso el aviso coincide con la fecha programada.
        DB::table('reminders')->whereNull('notify_at')->update([
            'notify_at' => DB::raw('scheduled_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropIndex(['status', 'notify_at']);
            $table->dropColumn(['notify_at', 'remind_before_minutes']);
        });
    }
};
