<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The installers repo: the SHARE DIRECTORY is the catalog — whatever's in the
 * folder is what /install offers; nothing is curated in the app. companies.
 * installers_url holds the human-facing share URL (smb:// or UNC); the server
 * indexes a local mount of it (INSTALLERS_MOUNT) into `installers` so
 * autocomplete works and missing installers can be flagged. Layout convention:
 * Mac/ and Windows/ top folders; 32/x86 vs 64/x64 in names carries the arch.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->string('installers_url', 500)->nullable()->after('local_domain'));
        Schema::create('installers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('relative_path', 500)->unique();
            $t->string('platform', 20)->default('other');
            $t->string('arch', 8)->nullable();
            $t->boolean('is_dir')->default(false);
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->timestamp('indexed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installers');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('installers_url'));
    }
};
