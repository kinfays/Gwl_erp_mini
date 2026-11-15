<?php

namespace App\Jobs\Commercial;

use App\Models\CommercialCustomerUpload;
use App\Models\User;
use App\Services\Commercial\Customers\CustomerBatchLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Undo this upload" (Phase 8b): voids, with exact rollback, every batch of the upload that is still the newest of its district. It
 * carries only ids and the reason. A district that has had a newer file since is left as it is (its batch page says why).
 */
class VoidCustomerListUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 7200;

    /** @param  list<int>|null  $districtIds  only these districts' batches are undone (a district user's own); null = every district of the upload */
    public function __construct(public readonly int $uploadId, public readonly int $userId, public readonly string $reason, public readonly ?array $districtIds = null) {}

    public function handle(CustomerBatchLifecycle $lifecycle): void
    {
        $upload = CommercialCustomerUpload::query()->find($this->uploadId);
        $user = User::query()->find($this->userId);

        if (! $upload || ! $user) {
            return;
        }

        $lifecycle->voidUpload($upload, $user, $this->reason, $this->districtIds);
    }
}
