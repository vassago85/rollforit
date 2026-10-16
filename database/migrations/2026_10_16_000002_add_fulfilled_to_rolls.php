<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rolls', function (Blueprint $table) {
            // Set when a paid, non-cancelled Shopify order lands matching the roller's
            // identity after the roll. Until it is set, the roller cannot roll again.
            $table->timestamp('fulfilled_at')->nullable()->after('is_test');
            // Shopify order gid, so an orders/cancelled webhook can undo the fulfillment.
            $table->string('fulfilled_order_id')->nullable()->after('fulfilled_at');
            $table->index(['shop_id', 'fulfilled_at']);
            $table->index('fulfilled_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('rolls', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'fulfilled_at']);
            $table->dropIndex(['fulfilled_order_id']);
            $table->dropColumn(['fulfilled_at', 'fulfilled_order_id']);
        });
    }
};
