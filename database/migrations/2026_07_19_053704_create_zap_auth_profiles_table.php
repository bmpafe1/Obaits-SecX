<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Login details for authenticated web scanning.
        //
        // The password column holds a Laravel-encrypted value, never plaintext,
        // and is never returned to the browser once saved. A tenant re-enters it
        // to change it. This is application credentials — meaningfully smaller
        // blast radius than infrastructure credentials, but still a real
        // escalation of what a breach of this platform would expose, which is
        // why the UI insists on a dedicated test account.
        Schema::create('zap_auth_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');                       // ISOLATION
            $table->string('name');                          // "Customer portal"
            $table->string('target_url');                    // must be a verified domain
            $table->string('login_url');

            // The hidden anti-CSRF field. Empty means the form has none.
            $table->string('token_name')->nullable();
            $table->string('username_field')->default('username');
            $table->string('password_field')->default('password');
            $table->string('extra_post_data')->nullable();   // e.g. Login=Login

            // Without a logged-in indicator ZAP cannot tell whether its session
            // survived, and a scan that silently drops to anonymous produces a
            // clean report that means nothing.
            $table->string('logged_in_regex');
            $table->string('logged_out_regex')->nullable();

            $table->string('username');
            $table->text('password');                        // ENCRYPTED AT REST

            // Proof, not assumption: recorded from a real crawl comparison.
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_verify_result')->nullable(); // authenticated|failed|unknown
            $table->integer('last_new_paths')->nullable();

            $table->timestamps();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zap_auth_profiles');
    }
};