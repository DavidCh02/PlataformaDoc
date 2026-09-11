<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca qué documentos nacieron en el editor de la plataforma
     * (Nuevo documento / Nuevo Word) frente a los subidos/importados.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('platform_created')->default(false)->after('imported_from');
        });

        DB::table('documents')->whereNull('imported_from')->update(['platform_created' => true]);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('platform_created');
        });
    }
};
