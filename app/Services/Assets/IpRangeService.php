<?php

namespace App\Services\Assets;

use App\Models\IctIpRange;

class IpRangeService
{
    /**
     * Validate a device IP against the active IP ranges configured for its
     * district/region. Returns a validation error message when ranges exist
     * for that location and the IP falls outside all of them; returns null
     * (no constraint) when no ranges are configured yet, so this never
     * blocks a save for locations that haven't been planned.
     */
    public function validateIpForLocation(?string $ip, ?int $districtId, ?int $regionId): ?string
    {
        if (! $ip) {
            return null;
        }

        $ranges = IctIpRange::query()
            ->where('is_active', true)
            ->where(function ($query) use ($districtId, $regionId) {
                $query->when($districtId, fn ($q) => $q->orWhere('district_id', $districtId));
                $query->when($regionId, fn ($q) => $q->orWhere('region_id', $regionId));
            })
            ->get();

        if ($ranges->isEmpty()) {
            return null;
        }

        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return null;
            }
        }

        return "IP {$ip} is outside the assigned IP range(s) for this location (".
            $ranges->pluck('label')->join(', ').').';
    }

    public function ipInRange(string $ip, IctIpRange $range): bool
    {
        if ($range->cidr) {
            return $this->ipInCidr($ip, $range->cidr);
        }

        $ipLong = ip2long($ip);
        $startLong = ip2long($range->start_ip);
        $endLong = ip2long($range->end_ip);

        if ($ipLong === false || $startLong === false || $endLong === false) {
            return false;
        }

        return $ipLong >= $startLong && $ipLong <= $endLong;
    }

    protected function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
