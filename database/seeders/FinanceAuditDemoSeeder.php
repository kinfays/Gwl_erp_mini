<?php

namespace Database\Seeders;

use App\Services\Finance\FinanceDemoDataService;
use Illuminate\Database\Seeder;

/**
 * Demo payment vouchers, suppliers and (inactive) demo accounts for Finance and Audit. NOT called from DatabaseSeeder: run it on
 * purpose with `php artisan db:seed --class=FinanceAuditDemoSeeder`. It is the same code as `php artisan finance:demo-data` (without
 * --with-users), so it refuses to run in production or while the module flag is off, and adds nothing if demo vouchers already exist.
 * Remove it again with `php artisan finance:demo-data --remove`.
 */
class FinanceAuditDemoSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $result = app(FinanceDemoDataService::class)->seed(false, fn (string $line) => $this->command?->line($line));
        } catch (\RuntimeException $exception) {
            $this->command?->error($exception->getMessage());

            return;
        }

        if (! $result['skipped']) {
            $this->command?->info("Demo data made: {$result['vouchers']} vouchers, {$result['suppliers']} suppliers.");
        }
    }
}
