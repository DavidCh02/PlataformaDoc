<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::create('files', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('original_name');
        $table->string('mime_type');
        $table->string('storage_path');
        $table->bigInteger('file_size');
        $table->foreignId('folder_id')->nullable()->constrained('folders')->onDelete('cascade');
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        $table->softDeletes(); // Papelera de reciclaje
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
