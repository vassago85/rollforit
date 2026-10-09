<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->boolean('pos_enabled')->default(false);
            $table->string('pos_secret_hash', 255)->nullable();
            $table->string('pos_secret_last_four', 4)->nullable();
            $table->timestamp('pos_secret_rotated_at')->nullable();
        });

        Schema::table('rolls', function (Blueprint $table) {
            $table->string('channel', 10)->default('online')->after('discount_ends_at');
            $table->string('phone_hash', 64)->nullable()->after('email_hash');
            $table->string('email_hash', 64)->nullable()->change();
            $table->index(['shop_id', 'channel', 'promo_date']);
        });

        // Replace the single per-email index with per-identity indexes. Phone and email
        // de-duplicate independently; a customer who gives us both deduplicates on both.
        DB::statement('DROP INDEX IF EXISTS rolls_one_per_email_per_day');
        DB::statement('CREATE UNIQUE INDEX rolls_one_per_email_per_day ON rolls (shop_id, email_hash, promo_date) WHERE is_test = false AND email_hash IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX rolls_one_per_phone_per_day ON rolls (shop_id, phone_hash, promo_date) WHERE is_test = false AND phone_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rolls_one_per_phone_per_day');
        DB::statement('DROP INDEX IF EXISTS rolls_one_per_email_per_day');
        DB::statement('CREATE UNIQUE INDEX rolls_one_per_email_per_day ON rolls (shop_id, email_hash, promo_date) WHERE is_test = false');

        Schema::table('rolls', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'channel', 'promo_date']);
            $table->dropColumn(['channel', 'phone_hash']);
            $table->string('email_hash', 64)->nullable(false)->change();
        });

        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn(['pos_enabled', 'pos_secret_hash', 'pos_secret_last_four', 'pos_secret_rotated_at']);
        });
    }
};
