<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_status_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('from_status_id')->nullable();
            $table->unsignedBigInteger('to_status_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->index(['post_id', 'created_at']);
            $table->foreign('post_id')->references('id')->on('roadmap_posts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_status_changes');
    }
};
