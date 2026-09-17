<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token único por usuario para vincular su chat de Telegram con el bot
     * mediante el enlace t.me/Bot?start=TOKEN (sin copiar IDs a mano).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_link_token', 64)->nullable()->unique()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_link_token']);
            $table->dropColumn('telegram_link_token');
        });
    }
};
