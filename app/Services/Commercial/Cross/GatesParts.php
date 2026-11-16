<?php

namespace App\Services\Commercial\Cross;

/**
 * The permission-by-PART rule of the analyses that need more than one file (Phase 8e) and of the trends (Phase 8f): a page is a list of parts, each saying
 * what it needs (reading, billing, customers, link, named). A part the viewer may not have is not computed and carries one line saying what is missing;
 * a part whose data is simply not there says why; otherwise it is built. The bundle $b holds `can` (what the viewer may have), `windows` (a title and a
 * reason per side) and a key per side that is non-null once its data is loaded.
 */
trait GatesParts
{
    /**
     * @param  array<string, array<string, mixed>>  $parts
     * @param  array<string, mixed>  $b
     * @param  list<string>  $needs  reading, billing, customers, link, named
     */
    protected function part(array &$parts, array $b, string $key, string $title, array $needs, callable $make): void
    {
        $missing = $this->missing($needs, $b);

        if ($missing !== []) {
            $parts[$key] = ['key' => $key, 'title' => $title, 'visible' => false, 'empty' => true, 'reason' => 'Hidden: it needs '.implode(' and ', $missing).'.', 'data' => null];

            return;
        }

        $absent = $this->absent($needs, $b);

        if ($absent !== []) {
            $parts[$key] = ['key' => $key, 'title' => $title, 'visible' => true, 'empty' => true, 'reason' => implode(' ', $absent), 'data' => null];

            return;
        }

        $parts[$key] = ['key' => $key, 'title' => $title, 'visible' => true, 'empty' => false, 'reason' => null, 'data' => $make()];
    }

    /** @return list<string> what the viewer lacks for these needs */
    protected function missing(array $needs, array $b): array
    {
        $out = [];

        foreach ($needs as $need) {
            $ok = match ($need) {
                'link' => $b['can']['billing'] && $b['can']['customers'],
                'named' => $b['can']['named'],
                default => $b['can'][$need] ?? false,
            };

            if (! $ok) {
                $out[] = match ($need) {
                    'reading' => 'the reading permission (commercial.view_reading)',
                    'billing' => 'the billing permission (commercial.view_billing)',
                    'customers' => config('gwl.commercial_customer_list_enabled') ? 'the customer-list analytics permission (commercial.view_customer_analytics)' : 'the customer list, which is switched off',
                    'link' => 'both the billing and the customer-list permissions',
                    'named' => 'the CCA performance permission (commercial.view_reader_performance)',
                    default => $need,
                };
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> the sides the viewer may have that hold nothing for the window */
    protected function absent(array $needs, array $b): array
    {
        $out = [];

        foreach ($needs as $need) {
            if ($need === 'named') {
                continue;
            }

            $w = $b['windows'][$need] ?? null;
            $has = $need === 'link' ? $b['link'] !== null : ($b[$need] ?? null) !== null;

            if (! $has) {
                $out[] = ($w['title'] ?? $need).': '.($w['reason'] ?? 'nothing has been loaded.');
            }
        }

        return array_values(array_unique($out));
    }
}
