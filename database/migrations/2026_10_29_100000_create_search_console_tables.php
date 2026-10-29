<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_console_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('original_filename', 255);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamps();
            $table->index(['project_id', 'kind', 'period_end', 'id'], 'sc_imports_project_kind_period');
        });

        Schema::create('search_console_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_console_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The query or page exactly as exported; the normalized form is derived.
            $table->text('key_text');
            $table->text('normalized_key');
            $table->char('key_hash', 64);
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->decimal('position', 7, 2);
            // Explicit names: generated ones exceed MariaDB's 64-character limit.
            $table->index(['search_console_import_id', 'key_hash'], 'sc_rows_import_key');
            $table->index(['search_console_import_id', 'impressions', 'id'], 'sc_rows_import_impressions');
            $table->index(['search_console_import_id', 'clicks', 'id'], 'sc_rows_import_clicks');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_console_rows');
        Schema::dropIfExists('search_console_imports');
    }
};
