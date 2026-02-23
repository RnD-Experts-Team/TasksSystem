<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
        public function up(): void
        {
            Schema::create('todos', function (Blueprint $table) {
                $table->id();

                $table->foreignId('workspace_id')
                    ->constrained()
                    ->cascadeOnDelete();

                // parent_id = null => todo رئيسي
                // parent_id != null => sub todo
                $table->foreignId('parent_id')
                    ->nullable()
                    ->constrained('todos')
                    ->cascadeOnDelete();

                $table->string('title');

                $table->enum('status', ['pending', 'inprogress', 'completed'])
                    ->default('pending');

                $table->date('due_date')->nullable();

                $table->timestamps();
            });
        }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
