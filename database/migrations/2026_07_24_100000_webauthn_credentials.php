<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passkeys. Each row is one authenticator a user enrolled — a laptop's
     * Touch ID, a phone, a security key. The credential id is the authenticator's
     * own identifier (base64url); public_key is the COSE key we verify
     * assertions against. Passwords stay as the fallback — the gate must never
     * brick — but the front door opens with a tap.
     */
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('credential_id', 512)->unique();     // base64url
            $t->text('public_key');                          // serialized credential source
            $t->string('name', 60);                          // "MacBook Touch ID"
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webauthn_credentials');
    }
};
