<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Links are optional context; removing the page or cluster keeps the task.
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('keyword_cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('todo');
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status', 'due_on', 'id']);
            $table->index(['project_id', 'page_id', 'id']);
            $table->index(['project_id', 'keyword_cluster_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
    }
};
