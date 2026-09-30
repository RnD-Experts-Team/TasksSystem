<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_abuse_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24);
            $table->char('visitor_id', 26)->nullable();
            $table->char('ip_hash', 32)->nullable();
            $table->unsignedBigInteger('board_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['type', 'created_at']);
            $table->index(['visitor_id', 'created_at']);
            $table->index(['ip_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_abuse_events');
    }
};
