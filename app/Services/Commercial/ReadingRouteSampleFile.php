<?php

namespace App\Services\Commercial;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Writes a workbook in the layout of the reading report BY ROUTE (rptReadingSumm) from a spec, with INVENTED data only. Used by the
 * sample command (commercial:reading-routes:sample, to try the screens without a real file), by the small fixture in tests/Fixtures, and
 * by the test workbook builder (Tests\Support\Commercial\ReportWorkbooks::readingRoute), so there is one writer of the layout.
 *
 * The layout: a "Document map" sheet listing the CCAs as "staffid - NAME", then ONE SHEET PER CCA with a merged filter cell (REPORT PERIOD
 * and REGION), the CCA label, a header row (Route, Verified Strength, Read, Skipped, Visited, Unvisited, each with # and %), one row per
 * route labelled "DISTRICT code" and a "<label> TOTALS :" row. The percentage columns carry deliberately WRONG numbers (99.9): the importer
 * must never read them.
 *
 * $spec: region ('ACCRA WEST'), period ('01-October-2026 - 31-October-2026'), header ('groups' = "Read" over "#" and "%" on two rows, as
 * the real export; 'explicit' = "Read #" and "Read %"), document_map (true; false leaves it out), map_extra (ids listed on the map but with
 * no sheet), ccas: list of ['id', 'name', 'routes' => list of ['label', 'strength', 'read', 'skipped', 'visited', 'unvisited' (default
 * strength - visited)], 'totals' => ['strength'|'read'|'skipped'|'visited' => the ABSOLUTE number the TOTALS row prints] or false for no
 * TOTALS row, 'title' => false to leave the title off, 'sheet' => the sheet name].
 */
final class ReadingRouteSampleFile
{
    /** The period of the sample: 21 days, so a PART of a month. */
    public const SAMPLE_PERIOD = '01-October-2026 - 21-October-2026';

    /** The district label no system knows (it asks for a match). */
    public const UNKNOWN_DISTRICT = 'ATLANTIS';

    /** The district names printed when none are given. */
    public const INVENTED_DISTRICTS = ['SAMPLE ONE', 'SAMPLE TWO', 'SAMPLE THREE', 'SAMPLE FOUR'];

    /**
     * @param  array<string, mixed>  $spec
     * @return string the path written ($path, or a new temporary .xlsx)
     */
    public static function write(array $spec, ?string $path = null): string
    {
        $region = $spec['region'] ?? 'ACCRA WEST';
        $period = $spec['period'] ?? '01-October-2026 - 31-October-2026';
        $header = $spec['header'] ?? 'groups';
        $ccas = $spec['ccas'] ?? [];

        $book = new Spreadsheet;
        $map = $book->getActiveSheet();
        $map->setTitle('Document map');

        if (($spec['document_map'] ?? true) !== false) {
            $map->setCellValue('A1', 'Customer Meter Reading Report - CCA Summary - By Route');
            $row = 3;

            foreach ($ccas as $cca) {
                $map->setCellValueExplicit("A{$row}", $cca['id'].' - '.$cca['name'], DataType::TYPE_STRING);
                $row++;
            }

            foreach ($spec['map_extra'] ?? [] as $extra) {
                $map->setCellValueExplicit("A{$row}", $extra, DataType::TYPE_STRING);
                $row++;
            }

            // The real "Document map" declares a used range out to column XFC; reading it cell by cell exhausts memory.
            $map->setCellValue('XFC72', 'x');
        }

        foreach ($ccas as $index => $cca) {
            $sheet = $book->createSheet();
            $sheet->setTitle($cca['sheet'] ?? 'Sheet'.($index + 2));

            if (($cca['title'] ?? true) !== false) {
                $sheet->setCellValue('A1', 'Customer Meter Reading Report - CCA Summary - By Route');
            }

            $sheet->setCellValueExplicit('B3', "REPORT PERIOD: {$period}\nREGION: {$region}\n", DataType::TYPE_STRING);
            $sheet->mergeCells('B3:K3');
            $label = $cca['id'].' - '.$cca['name'];
            $sheet->setCellValueExplicit('B5', $label, DataType::TYPE_STRING);

            if ($header === 'groups') {
                foreach (['B7' => 'Route', 'C7' => 'Verified Strength', 'D7' => 'Read', 'F7' => 'Skipped', 'H7' => 'Visited', 'J7' => 'Unvisited'] as $cell => $text) {
                    $sheet->setCellValue($cell, $text);
                }

                foreach (['D7:E7', 'F7:G7', 'H7:I7', 'J7:K7'] as $range) {
                    $sheet->mergeCells($range);
                }

                foreach (['D8', 'F8', 'H8', 'J8'] as $cell) {
                    $sheet->setCellValue($cell, '#');
                }

                foreach (['E8', 'G8', 'I8', 'K8'] as $cell) {
                    $sheet->setCellValue($cell, '%');
                }

                $row = 9;
            } else {
                foreach (['B7' => 'Route', 'C7' => 'Verified Strength', 'D7' => 'Read #', 'E7' => 'Read %', 'F7' => 'Skipped #', 'G7' => 'Skipped %', 'H7' => 'Visited #', 'I7' => 'Visited %', 'J7' => 'Unvisited #', 'K7' => 'Unvisited %'] as $cell => $text) {
                    $sheet->setCellValue($cell, $text);
                }

                $row = 8;
            }

            $sum = ['strength' => 0, 'read' => 0, 'skipped' => 0, 'visited' => 0, 'unvisited' => 0];

            foreach ($cca['routes'] ?? [] as $route) {
                $unvisited = array_key_exists('unvisited', $route) ? $route['unvisited'] : $route['strength'] - $route['visited'];
                $sheet->setCellValueExplicit("B{$row}", $route['label'], DataType::TYPE_STRING);
                $sheet->setCellValue("C{$row}", $route['strength']);
                $sheet->setCellValue("D{$row}", $route['read']);
                $sheet->setCellValue("E{$row}", 99.9);   // a wrong percentage: never read
                $sheet->setCellValue("F{$row}", $route['skipped']);
                $sheet->setCellValue("G{$row}", 99.9);
                $sheet->setCellValue("H{$row}", $route['visited']);
                $sheet->setCellValue("I{$row}", 99.9);
                $sheet->setCellValue("J{$row}", $unvisited);
                $sheet->setCellValue("K{$row}", 99.9);

                foreach (['strength' => $route['strength'], 'read' => $route['read'], 'skipped' => $route['skipped'], 'visited' => $route['visited'], 'unvisited' => $unvisited] as $key => $value) {
                    $sum[$key] += (int) $value;
                }

                $row++;
            }

            if (($cca['totals'] ?? []) !== false) {
                $totals = array_merge($sum, (array) ($cca['totals'] ?? []));
                $sheet->setCellValueExplicit("B{$row}", $label.' TOTALS :', DataType::TYPE_STRING);
                $sheet->setCellValue("C{$row}", $totals['strength']);
                $sheet->setCellValue("D{$row}", $totals['read']);
                $sheet->setCellValue("F{$row}", $totals['skipped']);
                $sheet->setCellValue("H{$row}", $totals['visited']);
                $sheet->setCellValue("J{$row}", $totals['unvisited']);
            }
        }

        $path ??= tempnam(sys_get_temp_dir(), 'comm').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /**
     * THE SAMPLE: twelve CCAs on 40 routes in four districts, with every awkward case the importer and the analyses must handle. Every
     * name, id and number is invented. The design (and the arithmetic the tests do by hand on it):
     *
     *   routes 7001 to 7040      route n is in district (n - 1) / 10 (so 7001-7010 in the first district, ...)
     *   an ordinary route        strength 100, read 70, skipped 10, visited 80
     *   91001 .. 91010           CCA c owns the four routes 4c - 3 .. 4c (91001: 7001-7004, 91002: 7005-7008, ...)
     *   7001                     under TWO CCAs: 91001 has the visits (strength 169, read 100, skipped 20, visited 120) and 91002 still lists it
     *                            (strength 165, no visits): 91001 is the owner and the strength counts once
     *   7013 (91004)             read 70 + skipped 10 is NOT visited 85: a flagged row, kept
     *   91003's TOTALS row       says visited 321 where its four rows add up to 320: a flagged sheet, kept
     *   91010                    four routes (7037 to 7040, strength 100 each) and ZERO visits
     *   91011                    listed with NO route
     *   91012                    one route in an UNKNOWN district ("ATLANTIS 9901", strength 50, read 20, skipped 5, visited 25)
     *   00000 System Administrator   the excluded account: skipped, with one route of its own
     *   the period               1 to 21 October 2026: PART of a month
     *
     * @param  list<string>  $districts  four district names as printed (the first holds routes 7001-7010, ...)
     * @return array<string, mixed> a spec for write()
     */
    public static function sampleSpec(string $region = 'ACCRA WEST', array $districts = self::INVENTED_DISTRICTS, string $period = self::SAMPLE_PERIOD): array
    {
        $districts = array_values($districts) + self::INVENTED_DISTRICTS;
        $route = function (int $n, array $over = []) use ($districts): array {
            return array_merge(['label' => $districts[intdiv($n - 1, 10)].' '.(7000 + $n), 'strength' => 100, 'read' => 70, 'skipped' => 10, 'visited' => 80], $over);
        };

        $ccas = [];

        for ($c = 1; $c <= 10; $c++) {
            $routes = [];

            foreach (range(4 * $c - 3, 4 * $c) as $n) {
                $over = match (true) {
                    $n === 1 => ['strength' => 169, 'read' => 100, 'skipped' => 20, 'visited' => 120],
                    $n === 13 => ['visited' => 85],
                    $c === 10 => ['read' => 0, 'skipped' => 0, 'visited' => 0],
                    default => [],
                };
                $routes[] = $route($n, $over);
            }

            // 91002 still lists route 7001 (the CCA it was taken from): strength 165, no visits
            if ($c === 2) {
                array_unshift($routes, $route(1, ['strength' => 165, 'read' => 0, 'skipped' => 0, 'visited' => 0]));
            }

            $ccas[] = ['id' => (string) (91000 + $c), 'name' => 'SAMPLE CCA '.str_pad((string) $c, 2, '0', STR_PAD_LEFT), 'routes' => $routes]
                + ($c === 3 ? ['totals' => ['visited' => 321]] : []);
        }

        $ccas[] = ['id' => '91011', 'name' => 'SAMPLE CCA 11', 'routes' => []];
        $ccas[] = ['id' => '91012', 'name' => 'SAMPLE CCA 12', 'routes' => [['label' => self::UNKNOWN_DISTRICT.' 9901', 'strength' => 50, 'read' => 20, 'skipped' => 5, 'visited' => 25]]];
        $ccas[] = ['id' => '00000', 'name' => 'System Administrator', 'routes' => [['label' => $districts[0].' 7999', 'strength' => 10, 'read' => 5, 'skipped' => 1, 'visited' => 6]]];

        return ['region' => $region, 'period' => $period, 'ccas' => $ccas];
    }
}
