<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The task log: flat, stamped, append-only. Not a thread — nobody argues in
     * an MSP task, they append facts ("waiting on vendor — Sam, Jul 12"). Notes
     * stays the freeform scratchpad; this is the audit trail of why a task is
     * still open.
     */
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->timestamps();
            $t->index(['task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
