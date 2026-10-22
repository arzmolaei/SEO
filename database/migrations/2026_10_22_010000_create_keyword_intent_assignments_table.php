<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_intent_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('intent', 32);
            $table->string('confidence', 16);
            $table->string('source', 32);
            $table->json('evidence')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->index(['keyword_id', 'is_current']);
            $table->index(['project_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_intent_assignments');
    }
};
