<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Track whether a winning roll's discount code was actually spent in a paid
     * order, not just issued. Merchants need this because the "Rolls today"
     * figures otherwise overstate real cost — the Rolls today card can read
     * "3 winners · R3,000" even when only 2 of those 3 codes were ever used.
     *
     * fulfilled_at (added in 2026_10_16_000002) tracks whether the ROLLER
     * placed any paid order afterwards — that is for the "owed roll" rule.
     * Those columns are intentionally kept separate: a roller can be unblocked
     * (fulfilled_at set) without ever redeeming their winning code.
     */
    public function up(): void
    {
        Schema::table('rolls', function (Blueprint $table) {
            $table->timestamp('redeemed_at')->nullable()->after('fulfilled_order_id');
            $table->string('redeemed_order_id')->nullable()->after('redeemed_at');
            $table->bigInteger('redeemed_amount')->nullable()->after('redeemed_order_id');
            $table->index(['shop_id', 'redeemed_at']);
            $table->index('redeemed_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('rolls', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'redeemed_at']);
            $table->dropIndex(['redeemed_order_id']);
            $table->dropColumn(['redeemed_at', 'redeemed_order_id', 'redeemed_amount']);
        });
    }
};
