<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App-wide (not per-shop) configuration the operator can edit from the UI
     * without SSHing in to touch .env. The envelope-encryption flag lets us
     * mix plain-text knobs (From name, Mailgun domain) with secrets (API key)
     * in the same table, so one `AppSetting::get()` call covers both.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
