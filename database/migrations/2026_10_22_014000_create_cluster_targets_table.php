<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('keyword_cluster_id')->unique();
            $table->foreignId('page_id')->nullable();
            $table->string('type', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
            $table->foreign(['project_id', 'keyword_cluster_id'], 'targets_cluster_project_fk')->references(['project_id', 'id'])->on('keyword_clusters')->cascadeOnDelete();
            $table->foreign(['project_id', 'page_id'], 'targets_page_project_fk')->references(['project_id', 'id'])->on('pages')->restrictOnDelete();
            $table->index(['project_id', 'page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_targets');
    }
};
