<?php

namespace App\Services\Commercial\Cross;

use App\Models\CommercialCustomerUpload;
use App\Models\CommercialImportBatch;
use App\Models\User;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\CommercialImportService;
use App\Services\Commercial\Customers\CustomerImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Loads the invented cross-file set (CrossSampleSet) through the REAL import paths: the region-wide customer list first (so the billing link has a
 * list to compare with), then the reading report by route and CCA, then the Detailed Billing Report (which links the list when it goes live). Nothing
 * is written any other way: every guard, route filing and rollup of a real upload applies. Used by `commercial:cross:sample` and by the tests.
 */
class CrossSampleLoader
{
    public function __construct(
        protected CommercialImportService $imports,
        protected BillingDetailsImportService $billing,
        protected CustomerImportService $customers,
    ) {}

    /**
     * @param  list<string>  $districts  the two district names as printed (they must exist in the region, or the uploads wait for a match)
     * @return array{reading: CommercialImportBatch, billing: CommercialImportBatch, customers: CommercialCustomerUpload, files: array<string, string>}
     */
    public function load(string $directory, array $districts, string $region, ?User $actor = null): array
    {
        return $this->loadFiles(CrossSampleSet::write($directory, $districts, $region), $actor);
    }

    /**
     * Imports the three files of a set that already exists (the command writes them, the tests also load the small fixture set), in the order above.
     *
     * @param  array{reading: string, billing: string, customers: string}  $files
     * @return array{reading: CommercialImportBatch, billing: CommercialImportBatch, customers: CommercialCustomerUpload, files: array<string, string>}
     */
    public function loadFiles(array $files, ?User $actor = null): array
    {
        $registered = $this->customers->register($files['customers'], basename($files['customers']), 'monthly', Carbon::parse(CrossSampleSet::LIST_AS_OF), $actor, 'Invented cross-file sample');
        $this->customers->process($registered['batch']);

        $file = new UploadedFile($files['reading'], basename($files['reading']), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $preview = $this->imports->preview($file);
        $reading = $this->imports->createBatch(['notes' => 'Invented cross-file sample'], $preview, null, $actor?->id);

        ['batch' => $queued] = $this->billing->register($files['billing'], basename($files['billing']), $actor, 'Invented cross-file sample');
        $billing = $this->billing->process($queued);

        return ['reading' => $reading, 'billing' => $billing, 'customers' => $registered['upload']->refresh(), 'files' => $files];
    }
}
