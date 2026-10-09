<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('rfi:snapshot-promo-days')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('rfi:refresh-tokens')->hourly()->withoutOverlapping();
Schedule::command('rfi:prune')->dailyAt('03:15');
