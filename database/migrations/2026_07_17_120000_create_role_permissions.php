<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overrides of the shipped permission defaults, so Roles & access can be edited.
 *
 * Only rows that differ from App\Support\Access::DEFAULTS are written. An empty table
 * means "as shipped", and improving the defaults later then moves every install that
 * never deliberately said otherwise.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->id();
            $t->string('role', 32);
            $t->string('permission', 64);
            $t->boolean('allowed');
            $t->timestamps();
            $t->unique(['role', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
