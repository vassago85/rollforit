<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            // 1-100. Null means no percentage cap: the only limit is max_payout.
            $table->unsignedTinyInteger('max_payout_percent')->nullable()->after('max_payout');
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn('max_payout_percent');
        });
    }
};
