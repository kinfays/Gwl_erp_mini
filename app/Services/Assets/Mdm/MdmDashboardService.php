<?php

namespace App\Services\Assets\Mdm;

use App\Models\MdmDeviceCommand;
use App\Models\MdmEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only aggregates for the MDM devices dashboard, always computed over the devices the viewer may see.
 */
class MdmDashboardService
{
    public function __construct(
        private readonly MdmAccessGuard $guard,
        private readonly MdmSettings $settings,
    ) {}

    /**
     * @return array{
     *     totals: array<string, int>,
     *     recent_commands: Collection,
     *     recent_enrollments: Collection,
     *     intake: ?array<string, mixed>
     * }
     */
    public function build(User $viewer): array
    {
        $visible = fn (): Builder => $this->guard->devices($viewer)->notDeleted();
        $staleBefore = now()->subHours(max(1, (int) config('gwl.mdm_stale_report_hours', 24)));

        return [
            'totals' => [
                'total' => $visible()->count(),
                'compliant' => $visible()->where('policy_compliant', true)->count(),
                'non_compliant' => $visible()->where('policy_compliant', false)->count(),
                'lost' => $visible()->where('is_lost', true)->count(),
                'needs_review' => $visible()->where('needs_review', true)->count(),
                'not_reported' => $this->notReported($visible(), $staleBefore)->count(),
            ],
            'recent_commands' => MdmDeviceCommand::query()
                ->whereIn('mdm_device_id', $this->guard->devices($viewer)->select('mdm_devices.id'))
                ->with(['device.asset', 'requester'])
                ->latest('requested_at')
                ->latest('id')
                ->limit(8)
                ->get(),
            'recent_enrollments' => $visible()
                ->with('asset.assignedTo')
                ->whereNotNull('enrolled_at')
                ->latest('enrolled_at')
                ->limit(8)
                ->get(),
            // Feed-wide numbers (every region's events), so only the unscoped roles see them.
            'intake' => $this->guard->isRegionScopedIct($viewer) ? null : $this->intakeHealth(),
        ];
    }

    /** Devices that have never reported, or not within the staleness window. */
    public function notReported(Builder $devices, \DateTimeInterface $staleBefore): Builder
    {
        return $devices->where(fn (Builder $q) => $q->whereNull('last_status_report_at')->orWhere('last_status_report_at', '<', $staleBefore));
    }

    /** @return array<string, mixed> */
    public function intakeHealth(): array
    {
        $last = MdmEvent::query()->latest('received_at')->latest('id')->first();

        return [
            'mode' => $this->settings->pubsubMode(),
            'last_received_at' => $last?->received_at,
            'last_type' => $last?->notification_type,
            'failed' => MdmEvent::query()->whereNotNull('error')->count(),
            'unprocessed' => MdmEvent::query()->whereNull('processed_at')->whereNull('error')->count(),
            'configured' => $this->settings->isPushMode()
                ? filled(config('gwl.mdm_pubsub_push_audience')) && filled(config('gwl.mdm_pubsub_push_token'))
                : filled(config('gwl.mdm_pubsub_subscription')),
        ];
    }
}
