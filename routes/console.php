<?php

use App\Events\Transport\DocumentExpiryDetected;
use App\Models\CreditUnionLoan;
use App\Models\Vehicle;
use App\Services\CreditUnion\LoanService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Visitors\VisitorService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('gwcl:auto-checkout-visitors', function (VisitorService $visitors) {
    $count = $visitors->autoCheckOutToday();

    $this->info($count.' visitor(s) auto-checked out.');
})->purpose('Auto-checkout visitors still inside at the configured closing time');

Schedule::command('gwcl:auto-checkout-visitors')
    ->dailyAt(config('gwl.auto_checkout_time', config('gwcl.visitors_auto_checkout_time', '18:00')));

Artisan::command('leave:forfeit-expired-carry-over {--dry-run : Report what would be forfeited without changing any balance}', function (LeaveBalanceService $balances) {
    $dryRun = (bool) $this->option('dry-run');
    $summary = $balances->forfeitExpiredCarryOver($dryRun);

    $this->info(sprintf(
        '%s %d day(s) of expired carry-over on %d annual balance(s).',
        $dryRun ? 'Would forfeit' : 'Forfeited',
        $summary['days'],
        $summary['balances']
    ));
})->purpose('Forfeit annual leave carry-over still unused after its expiry date');

Schedule::command('leave:forfeit-expired-carry-over')->dailyAt('00:30');

Artisan::command('transport:check-expiries', function () {
    $count = 0;

    Vehicle::query()
        ->where('status', '!=', Vehicle::STATUS_RETIRED)
        ->where(function ($query): void {
            $query->whereBetween('insurance_expiry_date', [today()->toDateString(), today()->addDays(90)->toDateString()])
                ->orWhereBetween('road_worthiness_expiry_date', [today()->toDateString(), today()->addDays(90)->toDateString()]);
        })
        ->chunkById(100, function ($vehicles) use (&$count): void {
            foreach ($vehicles as $vehicle) {
                foreach (['insurance' => $vehicle->insurance_expiry_date, 'road_worthiness' => $vehicle->road_worthiness_expiry_date] as $type => $date) {
                    if (! $date || $date->lt(today()) || $date->greaterThan(today()->addDays(90))) {
                        continue;
                    }

                    event(new DocumentExpiryDetected($vehicle, $type, (int) today()->diffInDays($date)));
                    $count++;
                }
            }
        });

    $this->info($count.' transport document expiry alert(s) checked.');
})->purpose('Check vehicle insurance and road-worthiness expiries');

Schedule::command('transport:check-expiries')->dailyAt('07:30');

// Android Enterprise (MDM). Schedules exist only while the feature flag is on. These need BOTH a running scheduler
// (`php artisan schedule:work`, or a per-minute cron / Task Scheduler entry) and a queue worker — see docs/assets/mdm.md.
if (config('gwl.mdm_enabled')) {
    // Pull delivery: every minute; the command itself does nothing in push mode unless run with --force.
    Schedule::command('mdm:poll-events')->everyMinute()->withoutOverlapping(10);
    // Safety net in both modes.
    Schedule::command('mdm:sync-devices')->dailyAt('02:15')->withoutOverlapping();
    Schedule::command('mdm:prune-events')->dailyAt('03:00');
}

Artisan::command('credit-union:post-deferred-loan-repayments', function (LoanService $loans) {
    $posted = 0;

    CreditUnionLoan::query()
        ->outstanding()
        ->with('member')
        ->orderBy('id')
        ->chunkById(100, function ($chunk) use ($loans, &$posted): void {
            foreach ($chunk as $loan) {
                $posted += $loans->postDeferredDeductionRepayments($loan);
            }
        });

    $this->info($posted.' deferred payroll loan repayment(s) posted.');
})->purpose('Post payroll loan repayments captured on deduction batches before their loan existed');
