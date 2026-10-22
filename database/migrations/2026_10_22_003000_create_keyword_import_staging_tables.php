<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('original_filename');
            $table->json('mapping');
            $table->string('status');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('staged_keyword_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->text('keyword_original')->nullable();
            $table->json('raw_payload');
            $table->json('mapped_payload')->nullable();
            $table->boolean('is_valid')->default(true);
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staged_keyword_rows');
        Schema::dropIfExists('import_batches');
    }
};
