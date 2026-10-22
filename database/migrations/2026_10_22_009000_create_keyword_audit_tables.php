<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->json('changes');
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'action', 'created_at']);
            $table->index(['workspace_id', 'project_id']);
        });

        Schema::create('activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('change_set_id')->constrained()->cascadeOnDelete();
            $table->string('event', 100);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'event', 'created_at']);
            $table->index(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
        Schema::dropIfExists('change_sets');
    }
};
