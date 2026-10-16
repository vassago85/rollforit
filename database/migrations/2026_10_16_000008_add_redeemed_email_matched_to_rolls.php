<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Tri-state flag that answers "did the shopper who used this winning code at
     * checkout verify the same email at roll time?":
     *
     *   true   emails matched. Same person all the way through.
     *   false  emails differed. Someone else used the code (shared code, friend
     *          getting a hand-me-down, abuse). The merchant should see this.
     *   null   we could not compare. Order had no email on it, or we have no
     *          email_hash on the roll for some reason. UI treats this as "no
     *          evidence either way".
     *
     * Only populated by RedemptionTracker during orders/paid or metrics backfill;
     * never set at roll time (we don't yet know who will check out).
     */
    public function up(): void
    {
        Schema::table('rolls', function (Blueprint $table) {
            $table->boolean('redeemed_email_matched')->nullable()->after('redeemed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rolls', function (Blueprint $table) {
            $table->dropColumn('redeemed_email_matched');
        });
    }
};
