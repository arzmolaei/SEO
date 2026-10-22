<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('display_text');
            $table->string('normalized_text');
            $table->timestamps();

            $table->unique(['project_id', 'normalized_text']);
        });

        Schema::create('keyword_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staged_keyword_row_id')
                ->constrained('staged_keyword_rows')
                ->cascadeOnDelete();
            $table->string('source');
            $table->text('original_text');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique('staged_keyword_row_id');
            $table->index(['keyword_id', 'source']);
        });

        Schema::table('import_batches', function (Blueprint $table): void {
            $table->timestamp('committed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn('committed_at');
        });

        Schema::dropIfExists('keyword_sources');
        Schema::dropIfExists('keywords');
    }
};
