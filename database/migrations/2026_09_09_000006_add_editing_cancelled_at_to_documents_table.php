<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca de cancelación del flujo "Modificar" (Explorador → Word).
     *
     * Cuando el usuario pulsa "Cancelar" en el modal de la plataforma, además de
     * liberar el bloqueo se graba aquí el instante: cualquier intento posterior
     * de Check-In desde el Add-in de Word es rechazado con un mensaje claro
     * ("La edición fue cancelada desde la plataforma") hasta que se descargue
     * de nuevo el documento con metadatos. Se reinicia a null al iniciar un
     * nuevo "Modificar" o al guardar una versión.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('editing_cancelled_at')->nullable()->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('editing_cancelled_at');
        });
    }
};