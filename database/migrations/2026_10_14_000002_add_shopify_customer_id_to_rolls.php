<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rolls', function (Blueprint $table) {
            $table->string('shopify_customer_hash', 64)->nullable()->after('phone_hash');
        });

        DB::statement('CREATE UNIQUE INDEX rolls_one_per_customer_per_day ON rolls (shop_id, shopify_customer_hash, promo_date) WHERE is_test = false AND shopify_customer_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rolls_one_per_customer_per_day');

        Schema::table('rolls', function (Blueprint $table) {
            $table->dropColumn('shopify_customer_hash');
        });
    }
};
