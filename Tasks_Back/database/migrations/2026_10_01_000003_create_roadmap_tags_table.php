<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('roadmap_boards')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('slug', 40);
            $table->string('color', 7)->default('#64748b');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['board_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_tags');
    }
};
