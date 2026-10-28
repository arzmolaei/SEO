<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->string('seo_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('h1', 255)->nullable();
            $table->string('on_page_status', 16)->default('todo');
            $table->text('on_page_notes')->nullable();
            $table->index(['project_id', 'on_page_status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'on_page_status', 'id']);
            $table->dropColumn(['seo_title', 'meta_description', 'h1', 'on_page_status', 'on_page_notes']);
        });
    }
};
