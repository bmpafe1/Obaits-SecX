<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_artifacts', function (\Illuminate\Database\Schema\Blueprint $table) {
            // When did we last confirm this artifact still exists in its tool?
            $table->timestamp('verified_at')->nullable();
            // Result of that check: verified | missing | unreachable | (null = never checked)
            $table->string('verify_status')->nullable();
            $table->text('verify_note')->nullable();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_artifacts', function (Blueprint $table) {
            //
        });
    }
};
