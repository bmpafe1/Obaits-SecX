<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_backup_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider'); // idrive_e2 | google_drive | onedrive | dropbox | proxmox_bs | backupexec
            $table->string('display_name');
            $table->text('credentials')->nullable(); // encrypted at the application layer, never plaintext
            $table->string('status')->default('connected'); // connected | needs_reauth | error
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backup_connections');
    }
};