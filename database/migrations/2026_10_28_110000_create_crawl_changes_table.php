<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_runs', function (Blueprint $table): void {
            $table->string('comparison_status', 24)->nullable();
            $table->foreignId('baseline_run_id')->nullable()->constrained('crawl_runs')->nullOnDelete();
            $table->json('change_counts')->nullable();
            $table->string('comparison_phase', 16)->nullable();
            $table->char('comparison_cursor', 64)->nullable();
        });

        Schema::create('crawl_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crawl_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('baseline_run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->char('url_hash', 64);
            $table->text('normalized_url');
            $table->string('change_type', 24);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['crawl_run_id', 'url_hash', 'change_type']);
            $table->index(['crawl_run_id', 'change_type', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_changes');
        Schema::table('crawl_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('baseline_run_id');
            $table->dropColumn(['comparison_status', 'change_counts', 'comparison_phase', 'comparison_cursor']);
        });
    }
};
