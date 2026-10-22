<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 16)->default('pending');
            $table->char('fingerprint', 64);
            $table->json('suggestion');
            $table->string('resolution', 16)->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'type', 'keyword_id']);
            $table->index(['project_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_items');
    }
};
