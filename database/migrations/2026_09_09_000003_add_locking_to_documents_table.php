<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bloqueo estricto + versión activa de cada documento editable con Word.
     * Sin edición colaborativa en tiempo real: un usuario reserva el
     * documento (lock) y lo libera al terminar (flujo estilo GitHub).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false);
            $table->foreignId('locked_by_id')->nullable()->after('is_locked')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('current_version_id')->nullable()->after('locked_at')
                ->constrained('document_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by_id');
            $table->dropConstrainedForeignId('current_version_id');
            $table->dropColumn(['is_locked', 'locked_at']);
        });
    }
};