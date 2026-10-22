<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('checkpoint_row')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_message')->nullable();
        });

        Schema::table('staged_keyword_rows', function (Blueprint $table): void {
            $table->string('row_fingerprint', 64)->nullable();
            $table->index(['import_batch_id', 'row_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::table('staged_keyword_rows', function (Blueprint $table): void {
            $table->dropIndex(['import_batch_id', 'row_fingerprint']);
            $table->dropColumn('row_fingerprint');
        });

        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn([
                'processed_rows',
                'checkpoint_row',
                'started_at',
                'completed_at',
                'failed_at',
                'failure_message',
            ]);
        });
    }
};
