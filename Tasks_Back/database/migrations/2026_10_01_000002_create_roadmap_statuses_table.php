<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('roadmap_boards')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('slug', 60);
            $table->string('color', 7)->default('#6366f1');
            $table->string('kind', 16)->default('open');               // open|planned|in_progress|done|closed
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_roadmap_column')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('locks_voting')->default(false);
            $table->timestamps();
            $table->unique(['board_id', 'slug']);
            $table->index(['board_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_statuses');
    }
};
