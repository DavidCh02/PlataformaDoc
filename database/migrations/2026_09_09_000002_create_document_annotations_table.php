<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comentarios/debate de la plataforma sobre una versión concreta del
     * historial (notas adicionales de otros usuarios, similar a comentarios
     * sobre un commit / pull request).
     */
    public function up(): void
    {
        Schema::create('document_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('comment');
            $table->timestamps();

            $table->index('document_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_annotations');
    }
};