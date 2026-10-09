<?php

use App\Events\Transport\DocumentExpiryDetected;
use App\Models\CreditUnionLoan;
use App\Models\Vehicle;
use App\Services\CreditUnion\LoanService;
use App\Services\Leave\AnnualEntitlementService;
use App\Services\Commercial\Customers\CustomerUploadReminders;
use App\Services\Commercial\UploadReminderService;
use App\Services\HealthSafety\HealthSafetyAlertService;
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

Artisan::command('leave:generate-entitlements {year? : Leave year, default the current one}', function (AnnualEntitlementService $entitlements) {
    $year = (int) ($this->argument('year') ?: now()->format('Y'));

    if ($year < 2000 || $year > 2100) {
        $this->error('Give a four-digit leave year, e.g. 2027.');

        return 1;
    }

    $counts = $entitlements->generate($year, 'artisan');

    $this->info(sprintf(
        '%d: %d created, %d updated, %d unchanged, %d skipped (contract staff, no leave).',
        $year,
        $counts['created'],
        $counts['updated'],
        $counts['unchanged'],
        $counts['skipped']
    ));

    return 0;
})->purpose('Generate (or bring up to date) every active employee\'s Annual leave entitlement for a year; safe to run again');

// Idempotent: on 1 January it creates the new year's entitlements, on any other day it only picks up staff who joined or
// were graded since. Runs before the carry-over forfeiture below so both see the same entitlements.
Schedule::command('leave:generate-entitlements')->dailyAt('00:20');

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

// Commercial: tell the officers of a region when no report of a type has arrived for too long (days set on the
// Commercial settings screen). Scheduled only while the module is on, and it needs the scheduler to be running.
if (config('gwl.commercial_module_enabled')) {
    Artisan::command('commercial:remind-uploads {--dry-run : List the overdue uploads and who would be told, without sending anything}', function (UploadReminderService $reminders) {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $reminders->remind(dryRun: $dryRun);

        if (! $summary['enabled']) {
            $this->info('Upload reminders are switched off in the Commercial settings.');

            return 0;
        }

        $this->info(sprintf(
            '%d overdue upload(s): %s %d reminder(s) to %d officer notification(s); %d already reminded recently.',
            $summary['overdue'],
            $dryRun ? 'would send' : 'sent',
            $summary['reminded'],
            $summary['notifications'],
            $summary['skipped']
        ));

        return 0;
    })->purpose('Remind officers of overdue Commercial report uploads');

    Schedule::command('commercial:remind-uploads')->dailyAt('07:45')->withoutOverlapping();

    // Customer list: per-district upload reminders (by the cadence set for each district) and the personal-data housekeeping.
    if (config('gwl.commercial_customer_list_enabled')) {
        Artisan::command('commercial:remind-customer-uploads {--dry-run : List the overdue districts and who would be told, without sending anything}', function (CustomerUploadReminders $reminders) {
            $dryRun = (bool) $this->option('dry-run');
            $summary = $reminders->remind(dryRun: $dryRun);

            $this->info(sprintf('%d overdue district(s): %s %d reminder(s) to %d officer notification(s); %d already reminded recently.', $summary['overdue'], $dryRun ? 'would send' : 'sent', $summary['reminded'], $summary['notifications'], $summary['skipped']));

            return 0;
        })->purpose('Remind officers of overdue customer-list uploads, district by district');

        Schedule::command('commercial:remind-customer-uploads')->dailyAt('07:50')->withoutOverlapping();
        Schedule::command('commercial:customers:purge')->dailyAt('03:30')->withoutOverlapping();
    }
}

// Health & Safety (design 8.18 B): one digest per recipient about due dates (extinguishers, kit items, PPE replacements,
// incident actions) and about reports and investigations left too long. Scheduled only while the module is on; it needs the
// scheduler to be running. The acknowledgement check runs hourly (a daily run would let a 24-hour limit slip to nearly 48
// hours); everything else runs daily at 06:30 in the application's timezone.
if (config('gwl.health_safety_module_enabled')) {
    Artisan::command('health-safety:send-alerts {--group=all : equipment, actions, incidents, acknowledgement or all} {--dry-run : Print who would be told what, and write and send nothing}', function (HealthSafetyAlertService $alerts) {
        $group = (string) $this->option('group');
        $groups = $group === 'all' ? [] : array_values(array_filter(array_map('trim', explode(',', $group))));

        foreach ($groups as $name) {
            if (! in_array($name, HealthSafetyAlertService::GROUPS, true)) {
                $this->error('Unknown group "'.$name.'". Use equipment, actions, incidents, acknowledgement or all.');

                return 2;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = $alerts->run($groups, $dryRun);

        foreach ($summary['lines'] as $line) {
            $this->line($line);
        }

        $this->info(sprintf(
            '%s%d item(s) announced to %d recipient(s) (%d digest(s) %s, %d failed); %d more recorded without a notice.',
            $dryRun ? '[dry run, nothing written or sent] ' : '',
            $summary['items'],
            $summary['recipients'],
            $summary['sent'],
            $dryRun ? 'would be sent' : 'sent',
            $summary['failed'],
            $summary['silent']
        ));

        // Non-zero when any recipient could not be told, so cron or a monitor notices a partial failure.
        return $summary['failed'] > 0 ? 1 : 0;
    })->purpose('Send the Health & Safety due-date and follow-up digests');

    Schedule::command('health-safety:send-alerts --group=acknowledgement')->hourly()->withoutOverlapping();
    Schedule::command('health-safety:send-alerts --group=all')->dailyAt('06:30')->withoutOverlapping();
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
