<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_briefs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The brief keeps its own keyword snapshot, so it survives a cluster
            // being undone or a page being removed.
            $table->foreignId('keyword_cluster_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->string('primary_keyword', 255);
            $table->json('secondary_keywords');
            $table->string('intent', 32)->default('unknown');
            $table->json('headings');
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('draft');
            $table->date('due_on')->nullable();
            $table->date('published_on')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status', 'id']);
            $table->index(['project_id', 'due_on', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_briefs');
    }
};
