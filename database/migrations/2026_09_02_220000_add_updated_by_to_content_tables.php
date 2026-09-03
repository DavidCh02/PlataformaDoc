<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['folders', 'files', 'documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('updated_by_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['folders', 'files', 'documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['updated_by_id']);
                $table->dropColumn('updated_by_id');
            });
        }
    }
};