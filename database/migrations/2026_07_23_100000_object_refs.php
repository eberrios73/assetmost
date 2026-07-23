<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reference graph: one edge per @-mention. A doc that says @PG-WS-1007
     * gets a durable row here, so "which SOPs mention this device?" is a lookup,
     * not a text search. Rows are synced from content on every save — the content
     * is the source of truth, this table is its index.
     */
    public function up(): void
    {
        Schema::create('object_refs', function (Blueprint $t) {
            $t->id();
            $t->string('from_type', 20);
            $t->unsignedBigInteger('from_id');
            $t->string('to_type', 20);
            $t->unsignedBigInteger('to_id');
            $t->timestamps();
            $t->unique(['from_type', 'from_id', 'to_type', 'to_id']);
            $t->index(['to_type', 'to_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('object_refs');
    }
};
