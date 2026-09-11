<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de versiones de cada documento editable con Word.
     * Cada fila representa un `.docx` guardado desde el Add-in con su anotación
     * de cambios (flujo estilo commit), el autor y la fecha.
     */
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('version_number', 20);
            $table->text('change_summary');
            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};