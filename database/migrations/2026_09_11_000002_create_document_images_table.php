<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las imágenes incrustadas en el editor viven AQUÍ, no en `files`:
     * así nunca aparecen en el explorador ni interfieren con versiones,
     * vinculados o descargas. Se migran las ya existentes preservando IDs
     * para no romper las URLs guardadas en los contenidos.
     */
    public function up(): void
    {
        Schema::create('document_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->string('storage_path');
            $table->string('mime_type')->default('application/octet-stream');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('name')->nullable();
            $table->string('original_name')->nullable();
            $table->timestamps();
        });

        $moved = DB::table('files')
            ->where('storage_path', 'like', 'files/%/images/%')
            ->whereNotNull('document_id')
            ->get();

        foreach ($moved as $file) {
            DB::table('document_images')->insert([
                'id' => $file->id,
                'document_id' => $file->document_id,
                'user_id' => $file->user_id,
                'folder_id' => $file->folder_id,
                'storage_path' => $file->storage_path,
                'mime_type' => $file->mime_type,
                'file_size' => $file->file_size,
                'name' => $file->name,
                'original_name' => $file->original_name,
                'created_at' => $file->created_at,
                'updated_at' => $file->updated_at,
            ]);
        }

        DB::table('files')
            ->where('storage_path', 'like', 'files/%/images/%')
            ->whereNotNull('document_id')
            ->delete();
    }

    public function down(): void
    {
        $moved = DB::table('document_images')->get();

        foreach ($moved as $image) {
            DB::table('files')->insert([
                'id' => $image->id,
                'document_id' => $image->document_id,
                'user_id' => $image->user_id,
                'folder_id' => $image->folder_id,
                'storage_path' => $image->storage_path,
                'mime_type' => $image->mime_type,
                'file_size' => $image->file_size,
                'name' => $image->name,
                'original_name' => $image->original_name,
                'created_at' => $image->created_at,
                'updated_at' => $image->updated_at,
            ]);
        }

        Schema::dropIfExists('document_images');
    }
};
