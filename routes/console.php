<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('gwcl:auto-checkout-visitors', function () {
    $count = \App\Models\Visitor::query()
        ->whereNull('check_out_at')
        ->whereDate('check_in_at', today())
        ->update([
            'check_out_at' => now(),
            'checked_out_by' => 'auto',
            'updated_at' => now(),
        ]);

    $this->info($count . ' visitor(s) auto-checked out.');
})->purpose('Auto-checkout visitors still inside at the configured closing time');

Schedule::command('gwcl:auto-checkout-visitors')
    ->dailyAt(config('gwcl.visitors_auto_checkout_time', '18:00'));
