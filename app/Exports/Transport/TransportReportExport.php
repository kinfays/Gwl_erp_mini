<?php

namespace App\Exports\Transport;

use App\Exports\Transport\Sheets\ArrayReportSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class TransportReportExport implements WithMultipleSheets
{
    public function __construct(
        protected array $payload,
        protected array $meta = []
    ) {}

    public function sheets(): array
    {
        return [
            new ArrayReportSheet('Summary', ['Metric', 'Value', 'Badge'], $this->summaryRows()),
            new ArrayReportSheet('Monthly Expenses', ['Month', 'Amount'], $this->pairedRows($this->payload['monthlyExpenses']['labels'] ?? [], $this->payload['monthlyExpenses']['data'] ?? [])),
            new ArrayReportSheet('Expense Types', ['Type', 'Amount'], $this->pairedRows($this->payload['expenseByType']['labels'] ?? [], $this->payload['expenseByType']['data'] ?? [])),
            new ArrayReportSheet('Vehicle Status', ['Status', 'Count'], $this->pairedRows($this->payload['vehicleStatusCounts']['labels'] ?? [], $this->payload['vehicleStatusCounts']['data'] ?? [])),
            new ArrayReportSheet('Departments', ['Department', 'Vehicles'], $this->pairedRows($this->payload['vehiclesByDepartment']['labels'] ?? [], $this->payload['vehiclesByDepartment']['data'] ?? [])),
            new ArrayReportSheet('Mileage', ['Month', 'Distance KM'], $this->pairedRows($this->payload['mileageByMonth']['labels'] ?? [], $this->payload['mileageByMonth']['data'] ?? [])),
            new ArrayReportSheet('Issues Trend', ['Month', 'Reported', 'Resolved'], $this->issuesTrendRows()),
            new ArrayReportSheet('Top Spend', ['Vehicle', 'Total Spend'], $this->pairedRows($this->payload['topExpensiveVehicles']['labels'] ?? [], $this->payload['topExpensiveVehicles']['data'] ?? [])),
            new ArrayReportSheet('Issues By Type', ['Type', 'Count'], $this->issuesByTypeRows()),
            new ArrayReportSheet('Renewals', ['Vehicle', 'Document', 'Expiry Date', 'Days Remaining'], $this->renewalRows()),
            new ArrayReportSheet('Maintenance Due', ['Vehicle', 'Current Mileage', 'Remaining KM', 'Next Service Mileage'], $this->maintenanceRows()),
        ];
    }

    protected function summaryRows(): array
    {
        return collect($this->payload['statCards'] ?? [])
            ->map(fn (array $card) => [
                $card['label'] ?? '',
                $card['value'] ?? '',
                $card['badge'] ?? '',
            ])
            ->values()
            ->all();
    }

    protected function pairedRows(array $labels, array $data): array
    {
        return collect($labels)
            ->values()
            ->map(fn ($label, int $index) => [$label, $data[$index] ?? 0])
            ->all();
    }

    protected function issuesTrendRows(): array
    {
        $trend = $this->payload['issuesTrend'] ?? [];

        return collect($trend['labels'] ?? [])
            ->values()
            ->map(fn ($label, int $index) => [
                $label,
                $trend['reported'][$index] ?? 0,
                $trend['resolved'][$index] ?? 0,
            ])
            ->all();
    }

    protected function issuesByTypeRows(): array
    {
        return collect($this->payload['issuesByType']['rows'] ?? [])
            ->map(fn (array $row) => [
                $row['label'] ?? '',
                $row['count'] ?? 0,
            ])
            ->all();
    }

    protected function renewalRows(): array
    {
        return collect($this->payload['upcomingExpiryDocs'] ?? [])
            ->map(fn (array $row) => [
                $row['vehicle'] ?? '',
                $row['document'] ?? '',
                $row['expiry_date'] ?? '',
                $row['days_remaining'] ?? '',
            ])
            ->all();
    }

    protected function maintenanceRows(): array
    {
        return collect($this->payload['maintenanceDueSoon']['rows'] ?? [])
            ->map(fn (array $row) => [
                $row['vehicle'] ?? '',
                $row['current_mileage'] ?? 0,
                $row['remaining_km'] ?? 0,
                $row['next_maintenance_mileage'] ?? 0,
            ])
            ->all();
    }
}
