<?php

namespace App\Providers;

use App\Support\MailConfigurator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(MailConfigurator $mail): void
    {
        // Overlay any Mailgun credentials the operator saved in the UI on top
        // of whatever .env said. This runs on every request (cheap: 60s cache
        // in the model) so the admin never has to restart PHP after a change.
        $mail->apply();
    }
}
