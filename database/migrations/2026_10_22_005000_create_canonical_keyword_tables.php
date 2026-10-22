<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->timestamp('committed_at')->nullable()->after('failed_at');
        });

        Schema::create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->text('display_text');
            $table->string('normalized_text', 768);
            $table->timestamps();

            $table->unique(['project_id', 'normalized_text']);
            $table->index(['project_id', 'id']);
        });

        Schema::create('keyword_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staged_keyword_row_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->text('original_text');
            $table->unsignedInteger('source_row_number');
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->unique('staged_keyword_row_id');
            $table->index(['keyword_id', 'source']);
            $table->index(['import_batch_id', 'source_row_number']);
        });

        Schema::create('keyword_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_source_id')->constrained()->cascadeOnDelete();
            $table->string('metric');
            $table->decimal('value', 20, 4)->nullable();
            $table->text('text_value')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['keyword_source_id', 'metric']);
            $table->index(['keyword_id', 'metric', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_metrics');
        Schema::dropIfExists('keyword_sources');
        Schema::dropIfExists('keywords');

        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn('committed_at');
        });
    }
};
