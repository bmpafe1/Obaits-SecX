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
        Schema::create('software_catalog', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('vendor')->nullable();
            $table->string('version')->nullable();
            $table->integer('seats_owned')->default(0);
            $table->string('license_key')->nullable();
            $table->string('license_type')->nullable();
            $table->decimal('annual_cost', 12, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->date('purchased_at')->nullable();
            $table->date('renews_at')->nullable();
            $table->string('owner')->nullable();
            $table->boolean('approved')->default(true);
            $table->string('match_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('tenant_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('software_catalog');
    }
};
