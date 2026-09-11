<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Elimina los restos de la integración OnlyOffice en `file_versions`.
     * El índice único (file_id, version) se conserva porque da soporte a la
     * FK de `file_id` en MySQL y sigue siendo útil para el versionado.
     * En SQLite el índice de `onlyoffice_key` debe quitarse antes que la columna.
     * La columna `source` conserva el valor 'upload' como configuración por
     * defecto (los registros históricos existentes con 'onlyoffice' se
     * conservan intactos).
     */
    public function up(): void
    {
        Schema::table('file_versions', function (Blueprint $table) {
            $table->dropIndex(['onlyoffice_key']);
        });

        Schema::table('file_versions', function (Blueprint $table) {
            $table->dropColumn(['onlyoffice_key', 'onlyoffice_version']);
        });
    }

    public function down(): void
    {
        Schema::table('file_versions', function (Blueprint $table) {
            $table->string('onlyoffice_key')->nullable();
            $table->integer('onlyoffice_version')->nullable();
            $table->index('onlyoffice_key');
        });
    }
};