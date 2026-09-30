<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);                                 // board|post|changelog
            $table->string('old_key', 120);                             // post: "{boardId}:{number}"
            $table->unsignedBigInteger('target_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['kind', 'old_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_slug_redirects');
    }
};
