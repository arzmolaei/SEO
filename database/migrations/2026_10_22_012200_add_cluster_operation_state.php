<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_clusters', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('keyword_clusters', function (Blueprint $table): void {
            $table->dropColumn(['revision', 'archived_at']);
        });
    }
};
