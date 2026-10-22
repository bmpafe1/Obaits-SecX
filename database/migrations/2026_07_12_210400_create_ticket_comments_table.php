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
         Schema::create('ticket_comments', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('tenant_id');                     // denormalised for isolation queries
            $table->uuid('author_id')->nullable();         // tenant_users.id; null = system
            $table->text('body');
            $table->boolean('is_internal')->default(false);// staff-only note
            $table->timestamps();
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->index(['tenant_id', 'ticket_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_comments');
    }
};
