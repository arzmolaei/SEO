<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_source_id')->constrained()->cascadeOnDelete();
            $table->string('metric');
            $table->decimal('value', 20, 4)->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['keyword_source_id', 'metric']);
            $table->index(['keyword_id', 'metric', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_metrics');
    }
};
