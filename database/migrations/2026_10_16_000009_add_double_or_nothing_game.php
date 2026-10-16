<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('game')->default('dice');
            $table->unsignedTinyInteger('base_percent')->default(5);
            $table->unsignedTinyInteger('double_percent')->default(10);
        });

        Schema::table('rolls', function (Blueprint $table) {
            $table->string('decision')->nullable();
            $table->timestamp('decision_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn(['game', 'base_percent', 'double_percent']);
        });

        Schema::table('rolls', function (Blueprint $table) {
            $table->dropColumn(['decision', 'decision_expires_at']);
        });
    }
};
