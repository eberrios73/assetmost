<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Declarative provisioning plugins: a JSON field map — auth recipe, one request,
 * response codes. Community plugins become paste-able JSON that CANNOT do
 * anything beyond the single request they declare, which is the only sane
 * trust model for third-party extensions inside a credential registry.
 * PHP-class plugins remain the escape hatch for exotic auth flows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('provisioner_definitions', function (Blueprint $t) {
            $t->id();
            $t->string('plugin_key', 40)->unique();
            $t->string('name');
            $t->longText('definition');
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioner_definitions');
    }
};
