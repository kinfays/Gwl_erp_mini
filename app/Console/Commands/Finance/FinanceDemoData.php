<?php

namespace App\Console\Commands\Finance;

use App\Services\Finance\FinanceDemoDataService;
use Illuminate\Console\Command;

/**
 * Realistic demo data for the Finance and Audit modules, made through the real services (docs/20-module-finance-audit.md).
 * Refuses to run in production, and unless GWL_FINANCE_AUDIT_ENABLED is on. Re-running adds nothing while demo vouchers exist.
 */
class FinanceDemoData extends Command
{
    protected $signature = 'finance:demo-data
        {--remove : Delete the demo data (DEMO- vouchers, demo suppliers, demo catalogue rows and demo accounts) and nothing else}
        {--with-users : Make the demo accounts usable (local or testing only): random passwords are printed once and must be changed at first sign-in}';

    protected $description = 'Create (or with --remove, delete) realistic demo payment vouchers, suppliers and accounts for Finance and Audit';

    public function handle(FinanceDemoDataService $demo): int
    {
        try {
            $demo->assertAllowed((bool) $this->option('with-users') && ! $this->option('remove'));

            if ($this->option('remove')) {
                $result = $demo->remove(fn (string $line) => $this->line($line));
                $this->info(sprintf(
                    'Done: %d vouchers, %d suppliers, %d catalogue rows, %d accounts and %d demo staff records removed.%s',
                    $result['vouchers'], $result['suppliers'], $result['catalogue'], $result['accounts'], $result['staff'],
                    $result['suppliers_kept'] ? " {$result['suppliers_kept']} demo supplier(s) kept because another voucher uses them." : ''
                ));

                return self::SUCCESS;
            }

            $result = $demo->seed((bool) $this->option('with-users'), fn (string $line) => $this->line($line));
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['skipped']) {
            return self::SUCCESS;
        }

        $this->info(sprintf('Done: %d locations, %d vouchers, %d suppliers, %d demo accounts.', $result['locations'], $result['vouchers'], $result['suppliers'], $result['accounts']));

        if ($result['credentials'] !== []) {
            $this->newLine();
            $this->warn('Demo sign-ins (shown ONCE; each must set a new password at first sign-in):');
            $this->table(['Account', 'Staff ID', 'Password'], array_map(fn (array $row) => [$row['account'], $row['staff_id'], $row['password']], $result['credentials']));
        } else {
            $this->line('The demo accounts are inactive (nobody can sign in as them). Add --with-users in a local environment to sign in.');
        }

        return self::SUCCESS;
    }
}
