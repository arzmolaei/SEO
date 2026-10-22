<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('kind', 16);
            $table->string('page_type', 32)->default('other');
            $table->text('url')->nullable();
            $table->text('normalized_url')->nullable();
            $table->char('url_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'url_hash']);
            $table->unique(['project_id', 'id'], 'pages_project_identity');
            $table->index(['project_id', 'kind', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
