<?php

namespace App\Services\Commercial;

use App\Models\CommercialImportBatch;
use Illuminate\Http\UploadedFile;

/**
 * The one door the upload screen uses: reads the workbook once, works out which report it is and hands it to the right
 * importer. The importers own all parsing, validation and writing.
 */
class CommercialImportService
{
    public function __construct(
        protected ReportFileReader $reader,
        protected ReadingSummaryImportService $reading,
        protected BillingSummaryImportService $billing,
    ) {
    }

    /**
     * @param  int|null  $restrictToRegionId  when set, a report for any other region is blocked
     */
    public function preview(UploadedFile $file, ?int $restrictToRegionId = null): array
    {
        // The customer list is far too big for this importer (and holds personal data): say where it goes, without loading it.
        if (config('gwl.commercial_customer_list_enabled') && $this->looksLikeCustomerList($file)) {
            $result = $this->unrecognised();
            $result['errors'] = [['row' => 'File', 'message' => 'This is the customer list report (rptCustomerDetails). Upload it on the Customer uploads screen: it is read in the background, it can be very large and it holds personal data.']];

            return $result;
        }

        $sheets = $this->reader->readSheets($file);

        return match ($this->reader->detectType($sheets)) {
            CommercialImportBatch::TYPE_READING_SUMMARY => $this->reading->preview($file, $restrictToRegionId, $sheets),
            CommercialImportBatch::TYPE_BILLING_SUMMARY => $this->billing->preview($file, $restrictToRegionId, $sheets),
            default => $this->unrecognised(),
        };
    }

    /**
     * @param  array{report_type: string, blocked?: bool, parsed: array<string, mixed>}  $preview
     */
    public function createBatch(array $batchAttributes, array $preview, ?string $importFilePath = null, ?int $actorId = null): CommercialImportBatch
    {
        if (! empty($preview['blocked'])) {
            throw new CommercialImportException('This file is blocked and cannot be imported.');
        }

        return match ($preview['report_type'] ?? null) {
            CommercialImportBatch::TYPE_READING_SUMMARY => $this->reading->createBatch($batchAttributes, $preview['parsed'], $importFilePath, $actorId),
            CommercialImportBatch::TYPE_BILLING_SUMMARY => $this->billing->createBatch($batchAttributes, $preview['parsed'], $importFilePath, $actorId),
            default => throw new CommercialImportException('This is not a recognised report.'),
        };
    }

    /** @return int how many readers (reading) or routes (billing) matched this time */
    public function rematchBatch(CommercialImportBatch $batch): int
    {
        return $batch->isReading() ? $this->reading->rematchBatch($batch) : $this->billing->rematchBatch($batch);
    }

    /** The sheet names alone say so (cheap): a sheet called rptCustomerDetails. */
    protected function looksLikeCustomerList(UploadedFile $file): bool
    {
        try {
            $names = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file->getRealPath())->listWorksheetNames($file->getRealPath());
        } catch (\Throwable) {
            return false;
        }

        return (bool) array_filter($names, fn (string $name) => preg_match('/^\s*rptCustomerDetails?\s*$/i', $name));
    }

    protected function unrecognised(): array
    {
        return [
            'report_type' => null,
            'blocked' => true,
            'errors' => [['row' => 'File', 'message' => 'This file is not one of the supported reports. Upload the "Customer Meter Reading Report - CCA Summary" (rptReadingSummDate) or the "Billing Summary Report By Routes" (rptBillingSumm_ExP) as an .xlsx export.']],
            'warnings' => [],
            'checks' => [],
            'error_count' => 1,
            'warning_count' => 0,
            'total_rows' => 0,
            'valid_count' => 0,
            'matched_count' => 0,
            'unmatched_count' => 0,
            'system_count' => 0,
            'region' => ['raw' => null, 'id' => null, 'name' => null],
            'unresolved_region' => null,
            'period' => ['from' => null, 'to' => null],
            'months' => [],
            'will_replace' => null,
            'preview_rows' => [],
            'parsed' => [],
        ];
    }
}
