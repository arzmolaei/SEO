<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_link_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // A decision is about two concrete pages; it goes when either page goes.
            $table->foreignId('source_page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('target_page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('anchor', 255)->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'source_page_id', 'target_page_id'], 'link_decisions_pair_unique');
            $table->index(['project_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_link_decisions');
    }
};
