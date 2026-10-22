<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('headscale_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('slug'); // mirrors tenant slug — used in every path/hostname
            $table->unsignedInteger('port_main');
            $table->unsignedInteger('port_metrics');
            $table->unsignedInteger('port_grpc');
            $table->unsignedInteger('headscale_user_id')->nullable();
            // Reusable, 1-year pre-auth key. Encrypted at rest — same
            // discipline as BackupConnections' credentials, via the Crypt
            // facade directly since this project has no Eloquent models.
            $table->text('preauth_key_encrypted')->nullable();
            $table->timestamp('preauth_key_expires_at')->nullable();
            $table->string('status')->default('provisioning'); // provisioning|active|failed
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
            $table->unique(['port_main', 'port_metrics', 'port_grpc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('headscale_instances');
    }
};
