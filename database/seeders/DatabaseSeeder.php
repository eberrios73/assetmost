<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo world: Plutonic Games. Model events stay ON — device asset
     * tags (PG-WS-1001…) are issued by the Device creating hook.
     */
    public function run(): void
    {
        $this->call(PlutonicGamesSeeder::class);
    }
}
