<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->integer('version')->default(1);
            $table->string('storage_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('upload');
            $table->string('onlyoffice_key')->nullable();
            $table->integer('onlyoffice_version')->nullable();
            $table->timestamps();

            $table->unique(['file_id', 'version']);
            $table->index('onlyoffice_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_versions');
    }
};
