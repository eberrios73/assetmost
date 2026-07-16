<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled device types with a 2-letter code.
 *
 * The code feeds the asset tag (PG-WS-1001) as a LABEL only — reporting and filtering
 * read device_type_id, never the tag string. Supersedes a free-text `type` column that
 * had drifted to 60 values (13 different spellings of "hard drive", one row whose type
 * was literally "UPS 006") and the App\Support\DeviceCategory shim that mapped around it.
 */
return new class extends Migration {
    /** name => code. Order is display order. */
    private const TYPES = [
        'Workstation'    => 'WS',
        'Laptop'         => 'LT',
        'Server'         => 'SR',
        'Monitor'        => 'MN',   // assigned per-person, so it stays its own type
        'Storage'        => 'ST',
        'Network'        => 'NT',
        'Infrastructure' => 'IN',
        'Peripheral'     => 'PE',
        'Phone'          => 'PH',
        'Tablet'         => 'TB',
        'Printer'        => 'PR',
        'Game Console'   => 'GC',
    ];

    /**
     * code => exact legacy `type` values (lowercased/trimmed) that map to it.
     * Inlined rather than read from DeviceCategory so this migration stays reproducible
     * if that class later goes away.
     */
    private const LEGACY = [
        'WS' => ['workstation'],
        'LT' => ['laptop'],
        'SR' => ['server'],
        'MN' => ['monitor'],
        'ST' => ['storage', 'storage nas', 'external hard drive', 'external hard drive ssd',
                 'hard drive', 'hard drive 1tb x 3', 'spare hard drives', 'ssd drive', 'hdd',
                 'g-drive 2tb', 'startup disk', 'external optical drive', 'external encrypted hd',
                 'external encrypted hd tm', 'external encrypted hd 1tb', 'external time machine encypted'],
        'NT' => ['switch', 'managed switch', 'wifi', 'airport extreme', 'airport express',
                 'firewall / router', 'fiber sfp x 6'],
        'IN' => ['ups', 'ups 006', 'power supply', 'thremostat', 'thermostat', 'polycom'],
        'PE' => ['peripheral', 'dongle', 'keyboard', 'kvm', 'external dock',
                 'thunderbolt dock statioon', 'wacom', 'cintiq', 'graphic tablet', 'writing tablet',
                 'camera', 'lens', 'analog / digital capture', 'video card', 'speakers',
                 'mixer board', 'microphone', 'motion capture suit', 'motion capture gloves',
                 'calibration', 'apple tv'],
        'PH' => ['iphone', 'phone', 'hot spot', 'iwatch'],
        'TB' => ['tablet', 'ipad'],
        'PR' => ['printer', 'scanner', 'fax'],
        'GC' => ['game console'],
    ];

    public function up(): void
    {
        Schema::create('device_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code', 2)->unique();      // the XX in XX-XX-XXXX
            $t->unsignedInteger('position')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        $now = now();
        $rows = [];
        $i = 0;
        foreach (self::TYPES as $name => $code) {
            $rows[] = ['name' => $name, 'code' => $code, 'position' => $i++,
                       'active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('device_types')->insert($rows);

        Schema::table('devices', function (Blueprint $t) {
            $t->foreignId('device_type_id')->nullable()->after('room_id')->constrained()->nullOnDelete();
        });

        // Map the legacy free-text column onto the controlled set. Exact matches only —
        // anything not listed stays null on purpose, because guessing the tail is how the
        // column got into this state. `type` is kept as-is for reference.
        $norm = "LOWER(TRIM(REPLACE(REPLACE(type, '\\n', ''), '\\r', '')))";
        foreach (self::LEGACY as $code => $rawValues) {
            $id = DB::table('device_types')->where('code', $code)->value('id');
            DB::table('devices')
                ->whereNull('device_type_id')
                ->whereRaw("$norm IN (" . implode(',', array_fill(0, count($rawValues), '?')) . ')', $rawValues)
                ->update(['device_type_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $t) => $t->dropConstrainedForeignId('device_type_id'));
        Schema::dropIfExists('device_types');
    }
};
