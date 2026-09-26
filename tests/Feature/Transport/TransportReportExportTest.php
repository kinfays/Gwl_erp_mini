<?php

namespace Tests\Feature\Transport;

use App\Exports\Transport\TransportReportExport;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class TransportReportExportTest extends TestCase
{
    public function test_report_renders_as_real_multi_sheet_xlsx(): void
    {
        $export = new TransportReportExport([
            'statCards' => [['label' => 'Vehicles', 'value' => 12, 'badge' => 'ok']],
            'monthlyExpenses' => ['labels' => ['Jan', 'Feb'], 'data' => [100.5, 200]],
        ]);

        $bytes = Excel::raw($export, ExcelWriter::XLSX);

        $path = tempnam(sys_get_temp_dir(), 'transport').'.xlsx';
        file_put_contents($path, $bytes);

        try {
            $spreadsheet = IOFactory::load($path);

            $this->assertCount(11, $spreadsheet->getSheetNames());
            $this->assertSame('Summary', $spreadsheet->getSheet(0)->getTitle());
            $this->assertSame('Vehicles', $spreadsheet->getSheet(0)->getCell('A2')->getValue());
            $this->assertSame('Feb', $spreadsheet->getSheetByName('Monthly Expenses')->getCell('A3')->getValue());
        } finally {
            @unlink($path);
        }
    }
}
