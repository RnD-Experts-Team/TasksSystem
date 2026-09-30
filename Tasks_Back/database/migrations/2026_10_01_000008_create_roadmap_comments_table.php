<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('parent_id')->nullable();        // one nesting level only
            $table->unsignedBigInteger('original_post_id')->nullable(); // set when moved by a merge
            $table->char('visitor_id', 26)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->string('author_name', 40)->nullable();
            $table->text('body');
            $table->string('moderation_state', 12)->default('pending');
            $table->timestamp('moderated_at')->nullable();
            $table->char('ip_hash', 32)->nullable();
            $table->char('content_hash', 40)->nullable();
            $table->json('flags')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'moderation_state', 'created_at']);
            $table->index('visitor_id');
            $table->index('ip_hash');
            $table->foreign('post_id')->references('id')->on('roadmap_posts')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('roadmap_comments')->cascadeOnDelete();
            $table->foreign('visitor_id')->references('id')->on('roadmap_visitors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_comments');
    }
};
