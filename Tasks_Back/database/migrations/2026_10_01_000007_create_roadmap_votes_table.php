<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->char('visitor_id', 26);
            $table->char('ip_hash', 32)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['post_id', 'visitor_id']);
            $table->index(['visitor_id', 'created_at']);
            $table->index(['ip_hash', 'created_at']);
            $table->index(['post_id', 'created_at']);
            $table->foreign('post_id')->references('id')->on('roadmap_posts')->cascadeOnDelete();
            $table->foreign('visitor_id')->references('id')->on('roadmap_visitors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_votes');
    }
};
