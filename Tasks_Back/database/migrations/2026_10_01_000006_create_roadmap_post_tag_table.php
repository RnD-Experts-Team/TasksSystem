<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_post_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['post_id', 'tag_id']);
            $table->index(['tag_id', 'post_id']);
            $table->foreign('post_id')->references('id')->on('roadmap_posts')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('roadmap_tags')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_post_tag');
    }
};
