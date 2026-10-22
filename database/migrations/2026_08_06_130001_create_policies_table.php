<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First slice deliberately minimal: no parameters_schema, no
        // scope/status marketplace fields yet -- those are real, but
        // premature before a single policy has been proven end to end.
        // owner_tenant_id NULL means a platform template (this slice's
        // only real policy). check_script_* is the REAL script content,
        // fetched by the device via /enroll/policy-script -- never sent
        // through Fleet's own script_contents, which corrupts backticks
        // and non-ASCII and hard-caps at 10,000 characters (confirmed
        // directly in this project's own role-setup scripts).
        Schema::create('policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_tenant_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('resource_key');
            $table->string('reversibility')->default('full'); // full|partial|none
            $table->unsignedInteger('version')->default(1);
            $table->text('check_script_windows')->nullable();
            $table->text('check_script_linux')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
