<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-visible audit trail of the storefront email-verification flow. Each
 * sendCode / verifyCode call lands here (success or failure), so a merchant can
 * see "shopper X tried, got rate-limited" or "shopper Y typed the wrong code
 * three times" without us having to tail the server log.
 *
 * The rolls table deliberately only keeps a hashed email (POPIA); this table
 * stores the plain email on the attempt row because the merchant needs to see
 * who tried in order to help them. Rows are append-only and pruned after a
 * fixed retention window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verify_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254)->nullable();
            $table->string('ip', 45)->nullable();
            // 'send' or 'verify' — which half of the flow this row represents.
            $table->string('action', 10);
            // Mirrors the VerificationRejected reason codes + 'queued' / 'confirmed'
            // for the happy paths. Kept as a flat string so we can add new outcomes
            // without migrating; the reporting UI maps unknown values to the raw code.
            $table->string('outcome', 32);
            // Only populated on verify flow (null, 1, 2, 3...) so the merchant can
            // see "3 of 5" wrong-code tries before the code was burned.
            $table->unsignedTinyInteger('attempt_number')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verify_attempts');
    }
};
