<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_clusters', function (Blueprint $table): void {
            $table->unsignedTinyInteger('business_relevance')->nullable()->after('review_state');
            $table->string('lifecycle_state', 16)->default('planning')->after('business_relevance');
            $table->index(['project_id', 'lifecycle_state', 'id'], 'clusters_project_lifecycle_index');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_clusters', function (Blueprint $table): void {
            $table->dropIndex('clusters_project_lifecycle_index');
            $table->dropColumn(['business_relevance', 'lifecycle_state']);
        });
    }
};
