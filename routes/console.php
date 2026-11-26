<?php

use App\Events\Transport\DocumentExpiryDetected;
use App\Models\Vehicle;
use App\Services\Leave\AnnualEntitlementService;
use App\Services\GWater\GwaterReminderService;
use App\Services\HealthSafety\HealthSafetyAlertService;
use App\Services\Finance\AuditReminderService;
use App\Services\Finance\FinanceReminderService;
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

    // A system job with no signed-in user, so it reads every vehicle on purpose; WHO is told is decided per vehicle by
    // TransportNotificationService / TransportScope::managersFor (managers of the vehicle's location, plus super_admin).
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

// Regional Blog: delete pictures that were uploaded but never used in an article, or that no article or saved version shows
// any more (older than gwl.blog_media_orphan_hours). Needs the scheduler running, like the other daily jobs.
if (config('gwl.blog_module_enabled')) {
    Schedule::command('blog:prune-media')->dailyAt('03:20')->withoutOverlapping();
    // Repair like counters from the like rows (they should never differ; this is the safety net). Weekly, Sunday night.
    Schedule::command('blog:recount-likes')->weeklyOn(0, '03:40')->withoutOverlapping();
}
// Android Enterprise (MDM). Schedules exist only while the feature flag is on. These need BOTH a running scheduler
// (`php artisan schedule:work`, or a per-minute cron / Task Scheduler entry) and a queue worker — see docs/assets/mdm.md.
if (config('gwl.mdm_enabled')) {
    // Pull delivery: every minute; the command itself does nothing in push mode unless run with --force.
    Schedule::command('mdm:poll-events')->everyMinute()->withoutOverlapping(10);
    // Safety net in both modes.
    Schedule::command('mdm:sync-devices')->dailyAt('02:15')->withoutOverlapping();
    Schedule::command('mdm:prune-events')->dailyAt('03:00');
}

// G-Water: tell a location's G-Water users when stock is low or a payment request to G Water is overdue (thresholds and days are the
// location's own settings). Scheduled only while the module is on, and it needs the scheduler to be running.
if (config('gwl.gwater_module_enabled')) {
    Artisan::command('gwater:remind {--dry-run : List what would be sent, without sending or recording anything}', function (GwaterReminderService $reminders) {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $reminders->remind(dryRun: $dryRun);

        $this->info(sprintf(
            '%d location(s): %d low-stock product(s); %s %d reminder(s) to %d notification(s); %d already reminded recently; %d with nobody to tell.',
            $summary['locations'],
            $summary['low_stock'],
            $dryRun ? 'would send' : 'sent',
            $summary['reminded'],
            $summary['notifications'],
            $summary['skipped'],
            $summary['nobody']
        ));

        return 0;
    })->purpose('Remind G-Water users of low stock');

    Schedule::command('gwater:remind')->dailyAt('07:55')->withoutOverlapping();
}

// Finance and Audit reminders (docs/20-module-finance-audit.md): TWO independent commands, each with its own settings per location
// (Finance > Settings > Reminders, Audit > Settings > Reminders): finance:remind (queried, ticked-and-waiting, drafts) and audit:remind
// (With Audit). A voucher is reminded about once per repeat interval. Scheduled only while the module is on, and they need the
// scheduler to be running.
if (config('gwl.finance_audit_enabled')) {
    $remindSummary = function ($command, array $summary, bool $dryRun): int {
        $command->info(sprintf(
            '%d voucher(s) waiting, %d overdue; %s %d reminder(s) (%d notification(s)); %d already reminded recently; %d at locations that switched reminders off.',
            $summary['waiting'],
            $summary['overdue'],
            $dryRun ? 'would send' : 'sent',
            $summary['reminded'],
            $summary['notifications'],
            $summary['skipped'],
            $summary['disabled']
        ));

        return 0;
    };

    Artisan::command('finance:remind {--dry-run : List what would be sent, without sending or recording anything}', function (FinanceReminderService $reminders) use ($remindSummary) {
        $dryRun = (bool) $this->option('dry-run');

        return $remindSummary($this, $reminders->remind(dryRun: $dryRun), $dryRun);
    })->purpose('Remind Finance officers of queried, ticked and draft payment vouchers that have waited too long');

    Artisan::command('audit:remind {--dry-run : List what would be sent, without sending or recording anything}', function (AuditReminderService $reminders) use ($remindSummary) {
        $dryRun = (bool) $this->option('dry-run');

        return $remindSummary($this, $reminders->remind(dryRun: $dryRun), $dryRun);
    })->purpose('Remind Audit officers of payment vouchers that have waited too long for a tick or a query');

    Schedule::command('finance:remind')->dailyAt('08:00')->withoutOverlapping();
    Schedule::command('audit:remind')->dailyAt('08:00')->withoutOverlapping();
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
