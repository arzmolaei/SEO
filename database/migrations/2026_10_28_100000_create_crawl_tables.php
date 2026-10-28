<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 16);
            $table->text('start_url');
            $table->text('normalized_start_url');
            $table->json('allowed_hosts');
            $table->json('settings');
            $table->string('status', 16);
            $table->unsignedInteger('discovered_count')->default(0);
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('pause_requested_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'id']);
        });

        Schema::create('crawl_urls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crawl_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->text('url');
            $table->text('normalized_url');
            $table->char('url_hash', 64);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('source', 16);
            $table->string('state', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('claim_token', 32)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('final_url')->nullable();
            $table->unsignedTinyInteger('redirect_count')->default(0);
            $table->string('content_type', 128)->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->text('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('robots', 255)->nullable();
            $table->text('canonical')->nullable();
            $table->json('h1')->nullable();
            $table->json('h2')->nullable();
            $table->json('hreflang')->nullable();
            $table->json('schema_types')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->unsignedInteger('images')->nullable();
            $table->unsignedInteger('images_missing_alt')->nullable();
            $table->unsignedInteger('internal_links')->nullable();
            $table->unsignedInteger('external_links')->nullable();
            $table->boolean('indexable')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['crawl_run_id', 'url_hash']);
            $table->index(['crawl_run_id', 'state', 'id']);
            $table->index(['crawl_run_id', 'status_code', 'id']);
            $table->index(['crawl_run_id', 'indexable', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_urls');
        Schema::dropIfExists('crawl_runs');
    }
};
