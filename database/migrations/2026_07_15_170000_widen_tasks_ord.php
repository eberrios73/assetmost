<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The IT-tasks app orders completed tasks by their creation ms-timestamp, so
 * `ord` can hold values well past INT range. Widen to BIGINT to import them faithfully.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->bigInteger('ord')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->integer('ord')->default(0)->change();
        });
    }
};
