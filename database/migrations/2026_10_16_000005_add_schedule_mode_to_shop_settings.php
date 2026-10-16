<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign mode. Historically the schedule was weekly only (`promo_days` =
 * list of ISO day numbers 1..7). Merchants running an event-driven promo
 * (Black Friday + the two weeks of a product launch, say) want to list the
 * actual calendar dates instead. We keep the weekly path as the default so
 * existing shops behave identically, and let a merchant flip to "dates" mode
 * where `promo_dates` becomes the authoritative list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('schedule_mode')->default('weekly')->after('end_time');
            $table->json('promo_dates')->default('[]')->after('schedule_mode');
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn(['schedule_mode', 'promo_dates']);
        });
    }
};
