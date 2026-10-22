<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // REAL BUG FOUND 2026-08-23, before shipping multi-key support:
        // key_prefix was sized at 12 chars, but "obaits_live_" itself is
        // already exactly 12 chars -- every key's prefix would display
        // identically, giving a tenant zero real way to visually tell
        // keys apart at a glance. Widened to fit "obaits_live_" plus 8
        // real, distinguishing hex characters.
        // REAL NOTE: uses raw SQL, not Schema::table()->change(), since
        // doctrine/dbal (required for ->change()) is not installed in
        // this project, and adding it just for one column resize isn't
        // worth a new real dependency.
        DB::statement('ALTER TABLE api_keys ALTER COLUMN key_prefix TYPE VARCHAR(20)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE api_keys ALTER COLUMN key_prefix TYPE VARCHAR(12)');
    }
};
