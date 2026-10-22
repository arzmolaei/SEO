<?php

use App\Enums\KeywordStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->string('status')
                ->default(KeywordStatus::Review->value)
                ->after('normalized_text');
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
