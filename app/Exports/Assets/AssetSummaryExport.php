<?php

namespace App\Exports\Assets;

use App\Exports\Assets\Sheets\SummarySheet;
use App\Services\Assets\AssetSummaryService;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** The Asset Summary page as a workbook, one sheet per tab. Built from AssetSummaryService::report(). */
class AssetSummaryExport implements Export, WithMultipleSheets
{
    /** @param array<string, mixed> $report the AssetSummaryService::report() payload */
    public function __construct(protected array $report) {}

    public function sheets(): array
    {
        return array_map(
            fn (array $sheet) => new SummarySheet($sheet['title'], $sheet['headings'], $sheet['rows']),
            self::tables($this->report)
        );
    }

    /**
     * The report as plain tables, shared by the Excel sheets and the PDF so both always agree.
     *
     * @return array<int, array{title: string, headings: array<int, string>, rows: array<int, array<int, string|int|float>>}>
     */
    public static function tables(array $report): array
    {
        $screens = ['Assets', 'Phones', 'Network'];
        $bucketRows = fn (string $section, array $buckets) => array_map(fn (array $b) => [
            $section, $b['label'], ...array_values($b['by_category']), $b['total'],
        ], $buckets);

        $manufacturerRows = [];
        foreach ($report['manufacturers'] as $group) {
            foreach ($group['result']['rows'] as $row) {
                $manufacturerRows[] = [$group['title'], $row['manufacturer'], '', $row['total'], $row['percentage'].'%'];
                foreach ($row['models'] as $model) {
                    $manufacturerRows[] = [$group['title'], $row['manufacturer'], $model['model'], $model['total'], $model['percentage'].'%'];
                }
            }
        }

        $maintenance = $report['maintenance'];
        $issues = $report['issues'];
        $pairs = fn (array $monthly) => array_map(null, $monthly['labels'], $monthly['data']);

        return [
            [
                'title' => 'Needs Attention',
                'headings' => ['Asset', 'Serial', 'Category', 'District', 'Why'],
                'rows' => $report['needsAttention']->map(fn (array $row) => [
                    $row['asset']->asset_name,
                    (string) $row['asset']->serial_number,
                    $row['asset']->device_category,
                    (string) $row['asset']->district?->district_name,
                    implode(', ', $row['reasons']),
                ])->all(),
            ],
            [
                'title' => 'Lifecycle',
                'headings' => ['Section', 'Bucket', ...$screens, 'Total'],
                'rows' => [
                    ...$bucketRows('Age', $report['age']),
                    ...$bucketRows('Warranty', $report['warranty']),
                    ...$bucketRows('Replacement', $report['replacement']),
                ],
            ],
            [
                'title' => 'Assignment',
                'headings' => ['Employee', 'Assets held'],
                'rows' => [
                    ['Unassigned devices', $report['unassigned']['total']],
                    ...$report['topAssignees']->map(fn (array $row) => [$row['name'], $row['total']])->all(),
                ],
            ],
            [
                'title' => 'Manufacturers & Models',
                'headings' => ['Group', 'Manufacturer', 'Model', 'Devices', 'Share'],
                'rows' => $manufacturerRows,
            ],
            [
                'title' => 'Maintenance',
                'headings' => ['Measure', 'Value', 'Detail'],
                'rows' => [
                    ['Tickets', $maintenance['total'], ''],
                    ['Average turnaround (days)', $maintenance['avg_turnaround_days'] ?? '', $maintenance['completed_counted'].' completed tickets counted'],
                    ...$maintenance['by_status']->map(fn ($total, $status) => ['Status', $total, $status])->values()->all(),
                    ...$maintenance['top_assets']->map(fn (array $row) => ['Most repaired', $row['total'], $row['asset']->asset_name.' ('.$row['asset']->serial_number.')'])->all(),
                    ...array_map(fn ($pair) => ['Opened in month', $pair[1], $pair[0]], $pairs($maintenance['monthly'])),
                ],
            ],
            [
                'title' => 'Reported Issues',
                'headings' => ['Measure', 'Value', 'Detail'],
                'rows' => [
                    ['Reports', $issues['total'], ''],
                    ...$issues['by_type']->map(fn ($total, $type) => ['Type', $total, $type])->values()->all(),
                    ...$issues['by_status']->map(fn ($total, $status) => ['Status', $total, $status])->values()->all(),
                    ...array_map(fn ($pair) => ['Raised in month', $pair[1], $pair[0]], $pairs($issues['monthly'])),
                ],
            ],
        ];
    }
}
