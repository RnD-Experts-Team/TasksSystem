<?php

// database/migrations/xxxx_xx_xx_add_visibility_indexes.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->index('section_id');
            $t->index('created_at');     // if you use ->latest()
        });

        Schema::table('sections', function (Blueprint $t) {
            $t->index('project_id');
        });

        Schema::table('projects', function (Blueprint $t) {
            $t->index('stakeholder_id');
            $t->index('status');
        });

        Schema::table('task_user', function (Blueprint $t) {
            $t->index(['task_id', 'user_id']);
            $t->index(['user_id', 'task_id']);
        });

        Schema::table('subtasks', function (Blueprint $t) {
            $t->index('task_id');
        });

        Schema::table('task_ratings', function (Blueprint $t) {
            $t->index('task_id');
            $t->index('rater_id');
        });

        // Schema::table('final_ratings', function (Blueprint $t) {
        //     $t->index('user_id');
        //     $t->index(['period_start', 'period_end']);
        // });

        Schema::table('stakeholder_ratings', function (Blueprint $t) {
            $t->index('project_id');
            $t->index('stakeholder_id');
        });
    }
    public function down(): void {}
};
