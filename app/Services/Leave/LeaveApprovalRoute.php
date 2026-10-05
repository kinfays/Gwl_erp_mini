<?php

namespace App\Services\Leave;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who a leave request goes to. Several people can hold the same role in a scope, so each stage is a set:
 * all of them are notified and the first to act wins.
 *
 * A single-stage route has no recommenders — the applicant applies straight to the final approver.
 */
final class LeaveApprovalRoute
{
    /**
     * @param  Collection<int, User>  $recommenders  empty when the route is single-stage
     * @param  Collection<int, User>  $approvers  never empty
     */
    public function __construct(
        public readonly Collection $recommenders,
        public readonly Collection $approvers,
        public readonly ?string $recommenderRole,
        public readonly string $approverRole,
        public readonly ?Collection $actingApprovers = null,
    ) {}

    /** Is $user in the approver set only because they are acting in the post (not because they hold it)? */
    public function isActing(User $user): bool
    {
        return ($this->actingApprovers?->contains('id', $user->id) ?? false);
    }

    public function isSingleStage(): bool
    {
        return $this->recommenders->isEmpty();
    }

    /** The first recommender (lowest user id): the one snapshotted on the request. */
    public function recommender(): ?User
    {
        return $this->recommenders->first();
    }

    public function approver(): User
    {
        return $this->approvers->first();
    }
}
