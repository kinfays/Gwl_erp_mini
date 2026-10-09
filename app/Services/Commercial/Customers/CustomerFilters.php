<?php

namespace App\Services\Commercial\Customers;

/**
 * What the customer screens are looking at: the batches (one effective batch per district), who is looking (the region
 * restriction decided by the caller) and the optional narrowing. Immutable; the analytics service never reads anything that
 * is not inside it.
 */
final class CustomerFilters
{
    /**
     * @param  list<int>  $batchIds  the effective batches in view
     * @param  int|null  $regionRestriction  null = every region; 0 = none; otherwise the one region the viewer may see
     */
    public function __construct(
        public readonly array $batchIds,
        public readonly ?int $regionRestriction = null,
        public readonly ?int $regionId = null,
        public readonly ?int $districtId = null,
        public readonly ?int $routeId = null,
        public readonly ?int $categoryId = null,
        public readonly ?string $group = null,
        public readonly ?int $statusId = null,
        public readonly ?int $meterStatusId = null,
        public readonly bool $billingOnly = false,
    ) {
    }

    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
