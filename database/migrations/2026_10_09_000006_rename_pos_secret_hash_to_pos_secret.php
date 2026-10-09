<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->renameColumn('pos_secret_hash', 'pos_secret');
        });

        Schema::table('shop_settings', function (Blueprint $table) {
            $table->text('pos_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('pos_secret', 255)->nullable()->change();
            $table->renameColumn('pos_secret', 'pos_secret_hash');
        });
    }
};
