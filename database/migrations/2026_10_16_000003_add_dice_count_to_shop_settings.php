<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            // 1 = single d10 (picks 1..10). 2 = two d6s (picks 2..12).
            // Odds are still driven by odds_n / odds_mode; dice count is purely display + pick range.
            $table->unsignedTinyInteger('dice_count')->default(1)->after('odds_n');
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn('dice_count');
        });
    }
};
