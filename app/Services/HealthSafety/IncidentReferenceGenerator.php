<?php

namespace App\Services\HealthSafety;

use App\Models\HsIncident;
use App\Models\Region;

/**
 * HS-<REGION PREFIX>-<YEAR>-<NNNN>, for example HS-AW-2026-0007. The prefix is the region's letter prefix (the same one
 * its letter serial numbers use). The next number is one more than the highest already issued for that prefix and year
 * (found by length then value, so 9999 sorts below 10000), and the unique index on `reference` is the final guard:
 * HealthSafety\IncidentWorkflowService::submit() asks for a new number again if two reports collide.
 */
class IncidentReferenceGenerator
{
    public function next(Region $region, ?int $year = null): string
    {
        $prefix = 'HS-'.$region->assignLetterPrefix().'-'.($year ?? now()->year).'-';

        $highest = HsIncident::query()
            ->where('reference', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(reference) desc')
            ->orderByDesc('reference')
            ->value('reference');

        $last = $highest ? (int) substr($highest, strlen($prefix)) : 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
