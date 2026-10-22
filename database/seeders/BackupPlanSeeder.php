<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackupPlanSeeder extends Seeder
{
    /**
     * Obaits backup storage ladder: $99 per TB per year, annual prepaid.
     * Cost basis is IDrive e2 MSP at $49.50/TB/year (standard renewal rate,
     * NOT the $24.75 first-year promotional price).
     *
     * gb_size uses the IDrive quota unit (GB, integer). 1 TB = 1024 GB.
     */
    public function run(): void
    {
        $tb = [1, 2, 5, 10, 20, 30, 40, 50, 100];

        foreach ($tb as $size) {
            $gb = $size * 1024;

            DB::table('backup_plans')->updateOrInsert(
                ['gb_size' => $gb],
                [
                    'id'              => DB::table('backup_plans')->where('gb_size', $gb)->value('id') ?? (string) Str::uuid(),
                    'price_usd_cents' => $size * 9900,
                    'active'          => true,
                    'updated_at'      => now(),
                    'created_at'      => now(),
                ]
            );
        }
    }
}
