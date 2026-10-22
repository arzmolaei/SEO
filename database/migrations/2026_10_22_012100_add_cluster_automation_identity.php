<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_clusters', function (Blueprint $table): void {
            $table->string('origin', 16)->default('user');
            $table->char('automation_key', 64)->nullable();
            $table->unique(['project_id', 'automation_key'], 'clusters_automation_identity');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_clusters', function (Blueprint $table): void {
            $table->dropUnique('clusters_automation_identity');
            $table->dropColumn(['origin', 'automation_key']);
        });
    }
};
