<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('source');
            $table->longText('content');
            $table->string('content_hash', 64);
            $table->timestamp('published_at');
            $table->unique(['shop_id', 'version']);
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('size');
            $table->unsignedSmallInteger('winning_position');
            $table->unsignedSmallInteger('next_position')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['shop_id', 'completed_at']);
        });

        Schema::create('rolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('block_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->nullable();
            $table->foreignId('terms_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email_hash', 64);
            $table->string('ip', 45)->nullable();
            $table->string('cart_token')->nullable();
            $table->date('promo_date');
            $table->bigInteger('cart_subtotal');
            $table->bigInteger('eligible_subtotal');
            $table->json('eligible_product_ids')->nullable();
            $table->unsignedSmallInteger('picked_number');
            $table->unsignedSmallInteger('displayed_number');
            $table->boolean('won')->default(false);
            $table->bigInteger('payout')->default(0);
            $table->string('discount_code')->nullable();
            $table->string('discount_node_id')->nullable();
            $table->timestamp('discount_ends_at')->nullable();
            $table->string('code_status')->default('none');
            $table->boolean('is_test')->default(false);
            $table->timestamps();

            $table->index(['shop_id', 'promo_date']);
            $table->index(['shop_id', 'discount_code']);
            $table->unique(['block_id', 'position']);
        });

        DB::statement('CREATE UNIQUE INDEX rolls_one_per_email_per_day ON rolls (shop_id, email_hash, promo_date) WHERE is_test = false');

        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('topic');
            $table->string('shopify_customer_id')->nullable();
            $table->unsignedInteger('matched_rolls')->default(0);
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('promo_day_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->date('promo_date');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedInteger('orders_count')->default(0);
            $table->bigInteger('revenue')->default(0);
            $table->bigInteger('merch_revenue')->default(0);
            $table->unsignedInteger('roller_orders_count')->default(0);
            $table->bigInteger('roller_revenue')->default(0);
            $table->unsignedInteger('winner_orders_count')->default(0);
            $table->bigInteger('winner_revenue')->default(0);
            $table->unsignedInteger('rolls_count')->default(0);
            $table->unsignedInteger('winners_count')->default(0);
            $table->bigInteger('prizes_issued')->default(0);
            $table->bigInteger('prizes_redeemed')->default(0);
            $table->boolean('is_final')->default(false);
            $table->timestamp('computed_at');
            $table->timestamps();
            $table->unique(['shop_id', 'promo_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_day_summaries');
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('rolls');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('terms_versions');
    }
};
