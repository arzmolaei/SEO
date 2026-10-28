<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->string('source_path', 512)->nullable()->after('original_filename');
            $table->unsignedBigInteger('source_bytes')->nullable()->after('source_path');
            $table->timestamp('commit_requested_at')->nullable()->after('committed_at');
            $table->foreignId('commit_requested_by')->nullable()->after('commit_requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable()->after('commit_requested_by');
            $table->foreignId('discarded_by')->nullable()->after('discarded_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('discarded_by');
            $table->dropColumn('discarded_at');
            $table->dropConstrainedForeignId('commit_requested_by');
            $table->dropColumn(['commit_requested_at', 'source_bytes', 'source_path']);
        });
    }
};
