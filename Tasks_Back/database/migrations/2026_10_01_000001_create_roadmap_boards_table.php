<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_boards', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->string('icon', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->string('voting_mode', 16)->default('anonymous');   // anonymous | verified_email (reserved)
            $table->boolean('allow_submissions')->default(true);
            $table->boolean('allow_comments')->default(true);
            $table->boolean('allow_votes')->default(true);
            $table->boolean('require_post_approval')->default(true);
            $table->boolean('require_comment_approval')->default(true);
            $table->unsignedSmallInteger('trust_after_approved')->nullable()->default(3);
            $table->unsignedInteger('next_post_number')->default(1);
            $table->timestamps();
            $table->index(['is_archived', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_boards');
    }
};
