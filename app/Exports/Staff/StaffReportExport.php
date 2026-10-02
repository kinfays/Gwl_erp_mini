<?php

namespace App\Exports\Staff;

use App\Exports\Transport\Sheets\ArrayReportSheet;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Staff Reports as a workbook: the headline figures, the staff by grade, the district numbers (with the staff by category
 * and how many have no grade yet), what is on leave now, and the staff list with grade columns. Built from the same
 * payload the page shows, so the export can never disagree with the screen.
 */
class StaffReportExport implements Export, WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $payload  StaffReportService::reportPayload()
     * @param  list<list<mixed>>  $staffRows  StaffReportService::exportStaffRows()
     * @param  array{from?: string, to?: string}  $meta
     */
    public function __construct(
        protected array $payload,
        protected array $staffRows,
        protected array $meta = []
    ) {}

    public function sheets(): array
    {
        return [
            new ArrayReportSheet('Summary', ['Metric', 'Value', 'Note'], $this->summaryRows()),
            new ArrayReportSheet('By Grade', ['Group', 'Grade / Category', 'Staff'], $this->gradeRows()),
            new ArrayReportSheet(
                'By District',
                ['District', 'Region', 'Total', 'Male', 'Female', 'On Leave', 'Junior Staff', 'Senior Staff', 'Management', 'Contract', 'No grade'],
                $this->districtRows()
            ),
            new ArrayReportSheet('On Leave Now', ['Employee', 'Leave', 'District', 'Region', 'From', 'To'], $this->onLeaveRows()),
            new ArrayReportSheet('Staff', ['Staff ID', 'Name', 'Department', 'Region', 'District', 'Category', 'Grade', 'Gender', 'Status'], $this->staffRows),
        ];
    }

    protected function summaryRows(): array
    {
        $rows = [['Period', ($this->meta['from'] ?? '').' to '.($this->meta['to'] ?? ''), $this->payload['scopeLabel'] ?? '']];

        foreach ($this->payload['statCards'] ?? [] as $card) {
            $rows[] = [$card['label'] ?? '', $card['value'] ?? '', $card['badge'] ?? ''];
        }

        return $rows;
    }

    protected function gradeRows(): array
    {
        $grades = $this->payload['gradeBreakdown'] ?? [];
        $rows = [];

        foreach ($grades['categories'] ?? [] as $row) {
            $rows[] = ['Category', $row['label'], $row['count']];
        }

        foreach (['senior' => 'Senior Staff', 'junior' => 'Junior Staff', 'management' => 'Management'] as $key => $group) {
            foreach ($grades[$key] ?? [] as $row) {
                $rows[] = [$group, $row['grade'], $row['count']];
            }
        }

        $rows[] = ['No grade set', 'All categories', $grades['no_grade']['count'] ?? 0];
        $rows[] = ['Total', 'Active staff', $grades['total'] ?? 0];

        return $rows;
    }

    protected function districtRows(): array
    {
        return collect($this->payload['districtRows'] ?? [])
            ->map(fn (array $row) => [
                $row['district'], $row['region'], $row['total'], $row['male'], $row['female'], $row['on_leave'],
                $row['junior'] ?? 0, $row['senior'] ?? 0, $row['management'] ?? 0, $row['contract'] ?? 0, $row['no_grade'] ?? 0,
            ])
            ->all();
    }

    protected function onLeaveRows(): array
    {
        return collect($this->payload['currentlyOnLeave'] ?? [])
            ->map(fn (array $row) => [$row['employee'], $row['leave_type'], $row['district'], $row['region'], $row['start_date'], $row['end_date']])
            ->all();
    }
}
