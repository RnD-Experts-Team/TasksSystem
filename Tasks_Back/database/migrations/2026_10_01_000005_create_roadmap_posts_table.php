<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('roadmap_boards')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('slug', 100);
            $table->string('title', 140);
            $table->text('body')->nullable();                           // plain text, never rendered as HTML
            $table->string('author_name', 40)->nullable();
            $table->char('visitor_id', 26)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->char('ip_hash', 32)->nullable();
            $table->char('content_hash', 40)->nullable();
            $table->string('moderation_state', 12)->default('pending'); // pending|approved|rejected|spam
            $table->timestamp('moderated_at')->nullable();
            $table->unsignedBigInteger('moderated_by')->nullable();
            $table->string('moderation_reason', 200)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('status_id')->constrained('roadmap_statuses')->restrictOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('roadmap_order')->default(0);
            $table->unsignedInteger('votes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);      // approved comments only
            $table->text('response_md')->nullable();
            $table->mediumText('response_html')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('responded_by')->nullable();
            $table->unsignedBigInteger('merged_into_post_id')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->json('flags')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->unique(['board_id', 'number']);
            $table->index(['board_id', 'moderation_state', 'votes_count']);
            $table->index(['board_id', 'moderation_state', 'published_at']);
            $table->index(['board_id', 'status_id', 'moderation_state']);
            $table->index('visitor_id');
            $table->index('ip_hash');
            $table->index('content_hash');
            $table->index('merged_into_post_id');
            $table->foreign('visitor_id')->references('id')->on('roadmap_visitors')->nullOnDelete();
            $table->foreign('merged_into_post_id')->references('id')->on('roadmap_posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_posts');
    }
};
