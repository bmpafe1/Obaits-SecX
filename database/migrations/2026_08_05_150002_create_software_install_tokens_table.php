<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL, IMPORTANT CORRECTION: a Fleet Script-triggered run (unlike
        // the original enrollment) has no access to the tenant's own real
        // OBAITS_ENROLL_SECRET at all -- that only exists as an env var
        // during the original install.sh/install.ps1 run. Matches the
        // same real, established pattern already proven for
        // role_credential_tokens: a real, short-lived, single-use token
        // minted specifically for this one software-install run.
        Schema::create('software_install_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token')->unique();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('software_file_id');
            $table->foreign('software_file_id')->references('id')->on('software_files')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_install_tokens');
    }
};
