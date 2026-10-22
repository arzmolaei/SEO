<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->unique(['project_id', 'id'], 'keywords_project_identity');
        });
        Schema::create('keyword_clusters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('primary_keyword_id');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('intent', 32)->default('unknown');
            $table->string('confidence', 16)->default('low');
            $table->string('review_state', 16)->default('pending');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
            $table->unique(['project_id', 'id'], 'clusters_project_identity');
            $table->index(['project_id', 'review_state', 'id']);
            $table->foreign(['project_id', 'primary_keyword_id'], 'clusters_primary_project_fk')
                ->references(['project_id', 'id'])->on('keywords')->restrictOnDelete();
        });
        Schema::create('keyword_cluster_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('keyword_cluster_id');
            $table->foreignId('keyword_id');
            $table->timestamps();
            $table->unique('keyword_id');
            $table->index(['project_id', 'keyword_cluster_id', 'id'], 'members_project_cluster_index');
            $table->foreign(['project_id', 'keyword_cluster_id'], 'members_cluster_project_fk')
                ->references(['project_id', 'id'])->on('keyword_clusters')->cascadeOnDelete();
            // Restrict keyword deletion until membership is explicitly handled.
            $table->foreign(['project_id', 'keyword_id'], 'members_keyword_project_fk')
                ->references(['project_id', 'id'])->on('keywords')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_cluster_members');
        Schema::dropIfExists('keyword_clusters');
        Schema::table('keywords', function (Blueprint $table): void {
            $table->dropUnique('keywords_project_identity');
        });
    }
};
