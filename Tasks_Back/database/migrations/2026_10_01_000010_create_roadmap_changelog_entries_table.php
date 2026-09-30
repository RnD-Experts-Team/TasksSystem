<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_changelog_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id')->nullable();         // null = all products
            $table->string('title', 140);
            $table->string('slug', 100)->unique();
            $table->string('summary', 280)->nullable();
            $table->string('label', 12)->default('new');                // new|improved|fixed
            $table->text('body_md')->nullable();
            $table->mediumText('body_html')->nullable();
            $table->string('status', 12)->default('draft');             // draft|published
            $table->timestamp('published_at')->nullable();              // future date = scheduled
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
            $table->index('board_id');
            $table->foreign('board_id')->references('id')->on('roadmap_boards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_changelog_entries');
    }
};
