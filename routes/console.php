<?php

use App\Events\Transport\DocumentExpiryDetected;
use App\Models\CreditUnionLoan;
use App\Models\Vehicle;
use App\Models\Visitor;
use App\Services\CreditUnion\LoanService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('gwcl:auto-checkout-visitors', function () {
    $count = Visitor::query()
        ->whereNull('check_out_at')
        ->whereDate('check_in_at', today())
        ->update([
            'check_out_at' => now(),
            'checked_out_by' => 'auto',
            'updated_at' => now(),
        ]);

    $this->info($count.' visitor(s) auto-checked out.');
})->purpose('Auto-checkout visitors still inside at the configured closing time');

Schedule::command('gwcl:auto-checkout-visitors')
    ->dailyAt(config('gwl.auto_checkout_time', config('gwcl.visitors_auto_checkout_time', '18:00')));

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
