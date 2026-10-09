<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);

            $table->string('odds_mode')->default('guaranteed');
            $table->unsignedSmallInteger('odds_n')->default(10);
            $table->json('promo_days')->default('[]');
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();

            // Money is stored in cents.
            $table->bigInteger('min_order')->nullable();
            $table->bigInteger('max_payout')->nullable();
            $table->bigInteger('daily_budget')->nullable();
            $table->unsignedInteger('max_winners_per_day')->nullable();
            $table->json('excluded_collections')->default('[]');

            $table->string('die_color', 7)->default('#1f2937');
            $table->string('die_number_color', 7)->default('#ffffff');
            $table->string('accent_color', 7)->default('#f59e0b');
            $table->unsignedInteger('roll_duration_ms')->default(2500);
            $table->boolean('sound_enabled')->default(true);
            $table->boolean('celebration_enabled')->default(true);

            $table->string('headline')->nullable();
            $table->text('win_message')->nullable();
            $table->text('lose_message')->nullable();

            $table->string('promoter_name')->nullable();
            $table->string('promoter_registration_number')->nullable();
            $table->text('promoter_address')->nullable();
            $table->string('promoter_email')->nullable();
            $table->string('promoter_phone')->nullable();

            $table->string('terms_mode')->default('generated');
            $table->string('generated_page_id')->nullable();
            $table->string('own_page_id')->nullable();
            $table->string('terms_url')->nullable();
            $table->timestamp('terms_published_at')->nullable();
            $table->boolean('terms_reviewed')->default(false);

            $table->json('advert_ack_snapshot')->nullable();
            $table->string('drawer_selector')->nullable();

            $table->string('preview_token_hash', 64)->nullable();
            $table->string('preview_outcome')->nullable();
            $table->timestamp('preview_expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
