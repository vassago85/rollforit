<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX rolls_one_per_guest_ip_per_day ON rolls (shop_id, ip, promo_date) WHERE is_test = false AND ip IS NOT NULL AND email_hash IS NULL AND phone_hash IS NULL AND shopify_customer_hash IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rolls_one_per_guest_ip_per_day');
    }
};
