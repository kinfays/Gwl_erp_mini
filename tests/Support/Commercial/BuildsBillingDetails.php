<?php

namespace Tests\Support\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\District;
use App\Models\User;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;

/**
 * Builders for the Detailed Billing Report tests (Phase 8d). Invented customers, accounts and routes only. Needs the host test to be a
 * CommercialTestCase. The sample's three districts are Sowutuom, Odorkor and Amasaman (all in Accra West); the small sample has too few routes
 * for ONE route that is one row out to stay under the 2% default, so a test that imports it calls tolerant() first.
 */
trait BuildsBillingDetails
{
    protected District $amasaman;

    protected function setUpBillingDetails(): void
    {
        $this->amasaman = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Amasaman']);
    }

    /** The two reconciliation limits widened, as the 8b fixture test does: one route in 21 is 4.8% of the routes. */
    protected function tolerant(): void
    {
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 10.0, 'gwl.commercial_reconcile_grand_total_tolerance_pct' => 1.0]);
    }

    /** @return array<string, mixed> THE SAMPLE spec with the test districts. */
    protected function sampleSpec(string $period = BillingDetailsSampleFile::SAMPLE_PERIOD): array
    {
        return BillingDetailsSampleFile::sampleSpec('ACCRA WEST', ['SOWUTUOM', 'ODORKOR', 'AMASAMAN'], $period);
    }

    /**
     * A route of customers $from..$to (each BillingDetailsSampleFile::customer($n, $over)): the building block of the small hand-computed specs.
     *
     * @param  array<string, mixed>  $over
     * @return array{label: string, customers: list<array<string, mixed>>}
     */
    protected function route(string $label, int $from, int $to, array $over = []): array
    {
        return ['label' => $label, 'customers' => array_map(fn (int $n) => BillingDetailsSampleFile::customer($n, $over), range($from, $to))];
    }

    /** @param  list<array<string, mixed>>  $routes @return array<string, mixed> */
    protected function spec(array $routes, string $period = 'September-2026 - September-2026', array $extra = []): array
    {
        return array_merge(['region' => 'ACCRA WEST', 'period' => $period, 'routes' => $routes], $extra);
    }

    /**
     * Writes a file from a spec (THE SAMPLE when none), registers it as an upload and runs the whole background run in the foreground.
     *
     * @param  array{review?: bool, actor?: User|null, restrict?: int|null, districts?: list<int>|null, name?: string, notes?: string|null}  $options
     */
    protected function importBilling(array $spec = [], array $options = []): CommercialImportBatch
    {
        $batch = $this->registerBilling($spec, $options);

        return app(BillingDetailsImportService::class)->process($batch);
    }

    /** Registers (stores the file, creates the QUEUED batch) without running it. */
    protected function registerBilling(array $spec = [], array $options = []): CommercialImportBatch
    {
        $path = ReportWorkbooks::billingDetails($spec === [] ? $this->sampleSpec() : $spec);
        $this->workbooks[] = $path;

        ['batch' => $batch] = app(BillingDetailsImportService::class)->register(
            $path, $options['name'] ?? 'rptBillingDetails-'.uniqid().'.xlsx', $options['actor'] ?? null, $options['notes'] ?? null, $options['review'] ?? false,
            $options['restrict'] ?? null, $options['districts'] ?? null
        );

        return $batch;
    }

    /** The one live Detailed Billing batch's rollup figure, summed (money as cedis). */
    protected function rollupSum(CommercialImportBatch $batch, string $column, array $where = []): float
    {
        $query = $batch->detailRollups();

        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }

        return round((float) $query->sum($column), 2);
    }
}
