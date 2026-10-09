<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialSetting;
use App\Models\Permission;
use App\Support\Audit;

/**
 * How often each district is expected to upload its customer list: weekly, monthly or not at all ("off"). Stored in
 * commercial_settings as one override row per district (customer_cadence.district.{id}); a district with no row uses
 * gwl.commercial_customer_default_cadence. The upload reminders and the "overdue" badge read it.
 */
class CustomerCadence
{
    public const OPTIONS = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'off' => 'Not expected'];

    protected const PREFIX = 'customer_cadence.district.';

    public function default(): string
    {
        $default = (string) config('gwl.commercial_customer_default_cadence', 'monthly');

        return array_key_exists($default, self::OPTIONS) ? $default : 'monthly';
    }

    public function forDistrict(int $districtId): string
    {
        return $this->all()[$districtId] ?? $this->default();
    }

    public function isCustom(int $districtId): bool
    {
        return isset($this->all()[$districtId]);
    }

    /** Days after which an upload is overdue under a cadence, or null when none is expected. */
    public function limitDays(string $cadence): ?int
    {
        return match ($cadence) {
            'weekly' => max(1, (int) config('gwl.commercial_customer_reminder_weekly_days', 10)),
            'monthly' => max(1, (int) config('gwl.commercial_customer_reminder_monthly_days', 35)),
            default => null,
        };
    }

    public function set(int $districtId, ?string $cadence, ?int $actorId): void
    {
        $name = self::PREFIX.$districtId;
        $before = $this->forDistrict($districtId);

        if ($cadence === null || $cadence === $this->default()) {
            CommercialSetting::query()->where('name', $name)->delete();
        } elseif (array_key_exists($cadence, self::OPTIONS)) {
            CommercialSetting::query()->updateOrCreate(['name' => $name], ['value' => $cadence, 'updated_by' => $actorId]);
        } else {
            return;
        }

        $after = $this->forDistrict($districtId);

        if ($before !== $after) {
            Audit::log(action: 'commercial.customer_cadence_changed', module: Permission::MODULE_COMMERCIAL, targetType: 'district', targetId: $districtId, metadata: ['from' => $before, 'to' => $after]);
        }
    }

    /**
     * district id => cadence, for the districts with their own. Read every time: a queue worker or the scheduler lives for
     * hours, and a change made on the settings screen must apply to the next reminder run, not after a restart.
     *
     * @return array<int, string>
     */
    protected function all(): array
    {
        return CommercialSetting::query()->where('name', 'like', self::PREFIX.'%')->pluck('value', 'name')
            ->mapWithKeys(fn ($value, $name) => [(int) substr((string) $name, strlen(self::PREFIX)) => (string) $value])->all();
    }
}
