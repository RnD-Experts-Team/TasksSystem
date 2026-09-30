<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_changelog_post', function (Blueprint $table) {
            $table->unsignedBigInteger('changelog_entry_id');
            $table->unsignedBigInteger('post_id');
            $table->primary(['changelog_entry_id', 'post_id']);
            $table->foreign('changelog_entry_id')->references('id')->on('roadmap_changelog_entries')->cascadeOnDelete();
            $table->foreign('post_id')->references('id')->on('roadmap_posts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_changelog_post');
    }
};
