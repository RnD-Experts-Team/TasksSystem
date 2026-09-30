<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_visitors', function (Blueprint $table) {
            $table->char('id', 26)->primary();                          // ULID
            $table->char('token_hash', 64)->unique();                   // sha256 of the opaque token
            $table->char('first_ip_hash', 32)->nullable();
            $table->char('last_ip_hash', 32)->nullable();
            $table->char('ua_hash', 16)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_banned')->default(false);
            $table->string('banned_reason', 200)->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->unsignedBigInteger('banned_by')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->unsignedInteger('posts_count')->default(0);
            $table->unsignedInteger('approved_posts_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('approved_comments_count')->default(0);
            $table->unsignedInteger('votes_count')->default(0);
            $table->timestamps();
            $table->index('first_ip_hash');
            $table->index('last_ip_hash');
            $table->index('is_banned');
            $table->index('first_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_visitors');
    }
};
