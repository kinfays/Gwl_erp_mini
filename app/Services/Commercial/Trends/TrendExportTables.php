<?php

namespace App\Services\Commercial\Trends;

/**
 * The tables of the Excel / PDF file of each trend (Phase 8f, design 8.45), built from the SAME payload the page shows (TrendReport::build), so a file
 * cannot disagree with its page. A part the viewer may not see is not in the file: a table named "Not in this file" says what is missing instead. Cells are
 * plain numbers or strings, '' for no value (the Commercial export keeps text as text).
 */
class TrendExportTables
{
    /**
     * @param  array<string, mixed>  $d  TrendReport::build()
     * @return list<array{title: string, headings: list<string>, rows: list<list<mixed>>}>
     */
    public function tables(string $key, array $d): array
    {
        $tables = match ($key) {
            't2', 't6' => $this->pairs($d),
            't4' => $this->rolling($d),
            't5' => $this->ranks($d),
            't7' => $this->projection($d),
            't8' => $this->timeliness($d),
            default => [],
        };

        $tables = array_merge($tables, $this->notInFile($d));

        return $tables === [] ? [['title' => 'Nothing to show', 'headings' => ['Note'], 'rows' => [['Nothing has been loaded for the parts you may see, or the range holds no upload.']]]] : $tables;
    }

    /** @return list<array<string, mixed>> */
    protected function notInFile(array $d): array
    {
        $rows = [];

        foreach ($d['parts'] as $part) {
            if (! $part['visible'] || $part['empty']) {
                $rows[] = [$part['title'], (string) $part['reason']];
            }
        }

        return $rows === [] ? [] : [['title' => 'Not in this file', 'headings' => ['Part', 'Why'], 'rows' => $rows]];
    }

    protected function data(array $d, string $part): ?array
    {
        $p = $d['parts'][$part] ?? null;

        return $p && $p['visible'] && ! $p['empty'] ? $p['data'] : null;
    }

    protected function n(mixed $value): mixed
    {
        return $value ?? '';
    }

    /** What a measure's unit is called in a file; a ratio changes in percentage points. */
    protected function unit(string $unit): string
    {
        return ['pct' => '% (a change is in percentage points)', 'm' => 'GH¢', 'n' => 'count'][$unit] ?? $unit;
    }

    protected function tone(?string $tone): string
    {
        return ['good' => 'Better', 'bad' => 'Worse', 'neutral' => 'No direction or no change'][$tone] ?? '';
    }

    // ---------------------------------------------------------------- T2 and T6

    /** @return list<array<string, mixed>> */
    protected function pairs(array $d): array
    {
        $out = [];
        $vs = $d['key'] === 't6' ? 'Same dates a year earlier' : 'Previous comparable period';

        foreach (['reading' => 'Reading by route and CCA', 'billing' => 'Detailed billing', 'customers' => 'Customer list'] as $kind => $title) {
            $p = $this->data($d, $kind);

            if ($p === null) {
                continue;
            }

            $out[] = ['title' => $title.': periods', 'headings' => ['Which', 'Period', 'Days'], 'rows' => array_values(array_filter([
                ['This period', (string) ($p['current']['label'] ?? ''), $p['current']['length'] ?? ''],
                [$vs, $p['compared'] ? (string) $p['compared']['label'] : ($p['note'] ?? 'none'), $p['compared']['length'] ?? ''],
            ]))];

            if ($p['measures'] !== []) {
                $out[] = ['title' => $title.': headline', 'headings' => $this->measureHeadings('Measure', $vs), 'rows' => array_map(fn (array $m) => $this->measureRow([$m['label']], $m), $p['measures'])];
            }

            foreach (['districts' => 'District', 'ccas' => 'CCA', 'routes' => 'Route'] as $set => $noun) {
                if (($p[$set] ?? []) === []) {
                    continue;
                }

                $rows = [];

                foreach ($p[$set] as $row) {
                    foreach ($row['measures'] as $m) {
                        $rows[] = $this->measureRow([$row['label'], $m['label']], $m);
                    }
                }

                $out[] = ['title' => $title.': by '.strtolower($noun), 'headings' => $this->measureHeadings($noun.' / Measure', $vs, true), 'rows' => $rows];
            }
        }

        return $out;
    }

    /** @return list<string> */
    protected function measureHeadings(string $first, string $vs, bool $two = false): array
    {
        return array_merge($two ? explode(' / ', $first) : [$first], ['This period', $vs, 'Change', 'Change (%)', 'Unit', 'Better or worse']);
    }

    /**
     * @param  list<string>  $lead
     * @return list<mixed>
     */
    protected function measureRow(array $lead, array $m): array
    {
        return array_merge($lead, [$this->n($m['now']), $this->n($m['then']), $this->n($m['change']), $this->n($m['change_pct']), $this->unit($m['unit']), $this->tone($m['tone'])]);
    }

    // ---------------------------------------------------------------- T4

    /** @return list<array<string, mixed>> */
    protected function rolling(array $d): array
    {
        $out = [];

        foreach (['reading' => 'Reading by route and CCA', 'billing' => 'Detailed billing'] as $kind => $title) {
            $p = $this->data($d, $kind);

            if ($p === null || ! $p['available']) {
                continue;
            }

            $keys = $p['measures'];
            $labels = TrendFigures::MEASURES[$kind] + ($kind === 'reading' ? ['visited' => ['label' => 'Visited']] : ['paid' => ['label' => 'Paid this month (GH¢)']]);
            $out[] = [
                'title' => $title.': trailing windows ending '.$p['end_label'],
                'headings' => array_merge(['Window', 'Period', 'Coverage of the window'], array_map(fn (string $k) => $labels[$k]['label'], $keys)),
                'rows' => array_map(fn (array $w) => array_merge([$w['days'].' days', $w['label'], $w['coverage_text']], array_map(fn (string $k) => $this->n($w['figures'][$k] ?? null), $keys)), $p['windows']),
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- T5

    /** @return list<array<string, mixed>> */
    protected function ranks(array $d): array
    {
        $out = [];

        foreach ($d['parts'] as $key => $part) {
            if (! $part['visible'] || $part['empty'] || ! ($part['data']['available'] ?? false)) {
                continue;
            }

            $p = $part['data'];
            $ratio = $p['measure'] === 'skip_rate' ? 'Skip rate (%)' : 'Collection ratio (%)';
            $out[] = [
                'title' => $part['title'].' ('.($p['ascending'] ? 'lowest is rank 1' : 'highest is rank 1').')',
                'headings' => [$p['noun'], $ratio.' now', $ratio.' before', 'Rank now', 'Rank before', 'Moved', 'Places'],
                'rows' => array_map(fn (array $r) => [$r['label'], $this->n($r['value_now']), $this->n($r['value_then']), $this->n($r['rank_now']), $this->n($r['rank_then']), $r['direction'], $this->n($r['move'])], $p['rows']),
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- T7

    /** @return list<array<string, mixed>> */
    protected function projection(array $d): array
    {
        $out = [];

        foreach (['reading' => 'Reading by route and CCA', 'billing' => 'Detailed billing'] as $kind => $title) {
            $p = $this->data($d, $kind);

            if ($p === null || ! $p['available']) {
                continue;
            }

            $out[] = [
                'title' => $title.': '.$p['month_label'].' ('.$p['coverage_text'].'; projection, not a forecast)',
                'headings' => ['Measure', 'Actual so far', 'Per covered day', 'Projection to month end', 'State'],
                'rows' => array_map(fn (array $m) => [$m['label'], $m['actual'], $this->n($m['per_day']), $this->n($m['projection']), ['projected' => 'Projected', 'complete' => 'Month fully covered', 'withheld' => 'Withheld: fewer than '.$p['min_days'].' days covered', 'none' => 'No days covered'][$m['state']] ?? $m['state']], $p['measures']),
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- T8

    /** @return list<array<string, mixed>> */
    protected function timeliness(array $d): array
    {
        $out = [];

        foreach ($d['parts'] as $part) {
            if (! $part['visible'] || $part['empty'] || ! ($part['data']['label'] ?? null)) {
                continue;
            }

            $p = $part['data'];
            $share = $p['on_time_share'] === null ? '' : $p['on_time_share'];

            $out[] = [
                'title' => $p['label'].' · '.$p['month_label'].' (on time = within '.$p['deadline_days'].' days; on-time share '.($share === '' ? 'not shown for a district user' : $share.'%').')',
                'headings' => ['District', 'Days covered', 'Days in month', 'Gaps', 'Uploads', 'On time', 'Late', 'On time (%)', 'Last import', 'Days since'],
                'rows' => array_map(fn (array $r) => [$r['name'], $r['covered_days'], $r['days_in_month'], $this->gaps($r['gaps']), $r['uploads'], $r['on_time'], $r['late'], $r['on_time_share'], $r['last_import'], $r['days_ago']], $p['districts']),
            ];
            $out[] = [
                'title' => $p['label'].': each upload',
                'headings' => ['Period', 'Imported', 'Days late (0 or less = on time)', 'On time'],
                'rows' => array_map(fn (array $u) => [$u['label'], $u['imported'], $u['lateness'], $u['late'] ? 'No' : 'Yes'], $p['uploads']),
            ];
            $out[] = [
                'title' => $p['label'].': districts by last upload',
                'headings' => ['District', 'Last import', 'Days since', 'Period'],
                'rows' => array_map(fn (array $r) => [$r['name'], $r['last_import'], $r['days_ago'], $r['last_label']], $p['recency']),
            ];
        }

        return $out;
    }

    /** @param  list<array{0: string, 1: string}>  $gaps */
    protected function gaps(array $gaps): string
    {
        return $gaps === [] ? 'none' : implode('; ', array_map(fn (array $g) => $g[0] === $g[1] ? $g[0] : $g[0].' to '.$g[1], $gaps));
    }
}
