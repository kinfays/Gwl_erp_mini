<?php

namespace App\Services\HealthSafety;

use App\Models\HsFirstAidItemTemplate;
use App\Models\HsFirstAidKit;
use App\Models\HsFirstAidKitCheck;
use App\Models\Region;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a first aid kit and to the kit templates goes through here. A kit is created pre-filled by COPYING the
 * templates for its type (editing a template later never changes a kit that already exists); a check updates item
 * quantities and expiry dates and the kit's last check in one transaction; checks are immutable rows.
 */
class FirstAidKitService
{
    public function __construct(
        protected EquipmentScope $scope,
        protected EquipmentLocation $location,
        protected EquipmentAssetCodeGenerator $codes,
    ) {}

    // ------------------------------------------------------------------ the register

    /**
     * @param  array<string, mixed>  $data  asset_code (blank to generate), kit_type, where it is, responsible_employee_id, last_checked_on, notes
     */
    public function create(User $actor, array $data, bool $audit = true): HsFirstAidKit
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_equipment'), 403, 'You may not add equipment.');

        $place = $this->location->resolve($actor, $data);
        $attributes = [...$this->validated($data), ...$place, 'status' => HsFirstAidKit::STATUS_IN_SERVICE, 'created_by' => $actor->id];

        $kit = $this->insert(Region::query()->findOrFail($place['region_id']), $attributes);

        if ($audit) {
            Audit::log('health_safety.kit_saved', 'health_safety', 'hs_first_aid_kits', $kit->id, ['asset_code' => $kit->asset_code, 'created' => true]);
        }

        return $kit;
    }

    /** @param  array<string, mixed>  $data */
    public function update(HsFirstAidKit $kit, User $actor, array $data): HsFirstAidKit
    {
        return DB::transaction(function () use ($kit, $actor, $data) {
            $locked = $this->lock($kit);
            $this->guardManage($actor, $locked);

            $place = $this->location->resolve($actor, $data);
            $locked->fill([...$this->validated($data, $locked), ...$place])->save();

            Audit::log('health_safety.kit_saved', 'health_safety', 'hs_first_aid_kits', $locked->id, ['asset_code' => $locked->asset_code, 'created' => false]);

            return $locked;
        });
    }

    /**
     * Replace the kit's contents list: add, rename, change the quantity it should hold or whether it expires, remove.
     * Current quantities and expiry dates are not touched here: those are recorded at a check.
     *
     * @param  list<array{id?: int|null, item_name: string, required_qty: int|string, has_expiry?: bool}>  $items
     */
    public function saveItems(HsFirstAidKit $kit, User $actor, array $items): HsFirstAidKit
    {
        return DB::transaction(function () use ($kit, $actor, $items) {
            $locked = $this->lock($kit);
            $this->guardManage($actor, $locked);

            $kept = [];

            foreach (array_values($items) as $index => $row) {
                $name = trim((string) ($row['item_name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $qty = $this->quantity($row['required_qty'] ?? 1, 'items.'.$index.'.required_qty', min: 1);
                $attributes = ['item_name' => mb_substr($name, 0, 150), 'required_qty' => $qty, 'has_expiry' => (bool) ($row['has_expiry'] ?? false), 'sort_order' => $index];

                $item = filled($row['id'] ?? null) ? $locked->items()->whereKey($row['id'])->first() : null;

                if ($item) {
                    $item->update($attributes);
                } else {
                    $item = $locked->items()->create([...$attributes, 'current_qty' => 0]);
                }

                $kept[] = $item->id;
            }

            $locked->items()->whereNotIn('id', $kept)->delete();

            Audit::log('health_safety.kit_saved', 'health_safety', 'hs_first_aid_kits', $locked->id, ['asset_code' => $locked->asset_code, 'created' => false, 'items' => count($kept)]);

            return $locked->load('items');
        });
    }

    // ------------------------------------------------------------------ checks

    /**
     * Record a check of the kit. $itemUpdates sets what the kit holds now (current_qty, expiry_date per item id); the
     * check passes only if, after those updates, no item is short or expired, and a fail needs a note on what is wrong.
     *
     * @param  array<string, mixed>  $data  checked_on, restocked, notes
     * @param  array<int, array{current_qty?: int|string|null, expiry_date?: string|null}>  $itemUpdates  keyed by item id
     */
    public function recordCheck(HsFirstAidKit $kit, User $actor, array $data, array $itemUpdates = []): HsFirstAidKitCheck
    {
        return DB::transaction(function () use ($kit, $actor, $data, $itemUpdates) {
            $locked = $this->lock($kit);

            abort_unless($this->scope->canCheck($actor, $locked), 403, 'You may not record a check on this kit.');
            $this->guardNotDecommissioned($locked);

            $checkedOn = $this->date($data['checked_on'] ?? today()->toDateString(), 'checked_on');

            if ($checkedOn->gt(today())) {
                throw ValidationException::withMessages(['checked_on' => 'The date cannot be in the future.']);
            }

            $items = $locked->items()->get()->keyBy('id');

            foreach ($itemUpdates as $itemId => $update) {
                $item = $items->get((int) $itemId);

                // An id that is not one of this kit's items is ignored, never applied to another kit's.
                if (! $item) {
                    continue;
                }

                $attributes = [];

                if (array_key_exists('current_qty', $update) && $update['current_qty'] !== null && $update['current_qty'] !== '') {
                    $attributes['current_qty'] = $this->quantity($update['current_qty'], 'items.'.$itemId.'.current_qty', min: 0);
                }

                if (array_key_exists('expiry_date', $update)) {
                    $attributes['expiry_date'] = filled($update['expiry_date']) ? $this->date($update['expiry_date'], 'items.'.$itemId.'.expiry_date') : null;
                }

                if ($attributes !== []) {
                    $item->update($attributes);
                }
            }

            $items = $locked->items()->get();
            $problems = $items->contains(fn ($item) => $item->isShort() || $item->isExpired());
            $result = $problems ? 'fail' : 'pass';
            $notes = filled($data['notes'] ?? null) ? trim($data['notes']) : null;

            if ($result === 'fail' && $notes === null) {
                throw ValidationException::withMessages(['notes' => 'Say what is wrong: something is missing or out of date.']);
            }

            $check = $locked->checks()->create([
                'checked_on' => $checkedOn,
                'checked_by' => $actor->id,
                'result' => $result,
                'restocked' => (bool) ($data['restocked'] ?? false),
                'notes' => $notes,
            ]);

            if ($locked->last_checked_on === null || $checkedOn->gte($locked->last_checked_on)) {
                $locked->forceFill(['last_checked_on' => $checkedOn, 'last_check_result' => $result])->save();
            }

            Audit::log('health_safety.kit_checked', 'health_safety', 'hs_first_aid_kits', $locked->id, [
                'asset_code' => $locked->asset_code,
                'result' => $result,
                'restocked' => $check->restocked,
            ]);

            return $check;
        });
    }

    // ------------------------------------------------------------------ status

    /** Mark a kit missing, or found again (back in service). */
    public function setMissing(HsFirstAidKit $kit, User $actor, bool $missing): HsFirstAidKit
    {
        return DB::transaction(function () use ($kit, $actor, $missing) {
            $locked = $this->lock($kit);
            $this->guardManage($actor, $locked);

            $to = $missing ? HsFirstAidKit::STATUS_MISSING : HsFirstAidKit::STATUS_IN_SERVICE;

            if ($locked->status !== $to) {
                $from = $locked->status;
                $locked->forceFill(['status' => $to])->save();

                Audit::log('health_safety.kit_saved', 'health_safety', 'hs_first_aid_kits', $locked->id, ['asset_code' => $locked->asset_code, 'from' => $from, 'to' => $to]);
            }

            return $locked;
        });
    }

    public function decommission(HsFirstAidKit $kit, User $actor, string $reason): HsFirstAidKit
    {
        return DB::transaction(function () use ($kit, $actor, $reason) {
            $locked = $this->lock($kit);
            $this->guardManage($actor, $locked);

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Give the reason it is being decommissioned.']);
            }

            $locked->forceFill([
                'status' => HsFirstAidKit::STATUS_DECOMMISSIONED,
                'decommissioned_on' => today(),
                'decommission_reason' => mb_substr($reason, 0, 1000),
            ])->save();

            Audit::log('health_safety.kit_decommissioned', 'health_safety', 'hs_first_aid_kits', $locked->id, ['asset_code' => $locked->asset_code]);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ templates

    /**
     * Replace the template for one kit type. Existing kits keep what they were created with.
     *
     * @param  list<array{item_name: string, required_qty: int|string, has_expiry?: bool}>  $items
     */
    public function saveTemplates(User $actor, string $kitType, array $items): int
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_master_data'), 403, 'You may not change the kit templates.');

        if (! array_key_exists($kitType, HsFirstAidKit::TYPES)) {
            throw ValidationException::withMessages(['kit_type' => 'Choose a kit type.']);
        }

        return DB::transaction(function () use ($kitType, $items) {
            $names = [];

            foreach (array_values($items) as $index => $row) {
                $name = trim((string) ($row['item_name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $key = mb_strtolower($name);

                if (isset($names[$key])) {
                    throw ValidationException::withMessages(['items' => '"'.$name.'" is listed twice.']);
                }

                $names[$key] = [
                    'item_name' => mb_substr($name, 0, 150),
                    'required_qty' => $this->quantity($row['required_qty'] ?? 1, 'items.'.$index.'.required_qty', min: 1),
                    'has_expiry' => (bool) ($row['has_expiry'] ?? false),
                    'sort_order' => $index,
                ];
            }

            HsFirstAidItemTemplate::query()->where('kit_type', $kitType)->delete();

            foreach ($names as $attributes) {
                HsFirstAidItemTemplate::query()->create([...$attributes, 'kit_type' => $kitType]);
            }

            Audit::log('health_safety.kit_template_saved', 'health_safety', 'hs_first_aid_item_templates', null, [
                'kit_type' => $kitType,
                'items' => count($names),
            ]);

            return count($names);
        });
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validated(array $data, ?HsFirstAidKit $existing = null): array
    {
        $validated = Validator::make($data, [
            'asset_code' => ['nullable', 'string', 'max:40', Rule::unique('hs_first_aid_kits', 'asset_code')->ignore($existing?->id)],
            'kit_type' => ['required', Rule::in(array_keys(HsFirstAidKit::TYPES))],
            'location_detail' => ['nullable', 'string', 'max:255'],
            'responsible_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'last_checked_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['kit_type' => 'type', 'asset_code' => 'asset code'])->validate();

        $attributes = [];

        foreach (['asset_code', 'kit_type', 'location_detail', 'responsible_employee_id', 'last_checked_on', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $validated[$field] ?? null;
                $attributes[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            }
        }

        // A kit nobody has checked yet has no "last result".
        if (($attributes['last_checked_on'] ?? null) !== null && $existing === null) {
            $attributes['last_check_result'] = 'pass';
        }

        return $attributes;
    }

    /**
     * Create the kit with its contents copied from the templates for its type (none, if there are none: the screen
     * then points at Kit templates). A generated tag that collides is asked for again; a typed one that does is the
     * user's to change.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function insert(Region $region, array $attributes): HsFirstAidKit
    {
        $typed = filled($attributes['asset_code'] ?? null);
        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($region, $attributes, $typed) {
                    $kit = HsFirstAidKit::query()->create([
                        ...$attributes,
                        'asset_code' => $typed ? $attributes['asset_code'] : $this->codes->nextKit($region),
                    ]);

                    HsFirstAidItemTemplate::query()
                        ->where('kit_type', $kit->kit_type)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->get()
                        ->each(fn (HsFirstAidItemTemplate $template) => $kit->items()->create([
                            'item_name' => $template->item_name,
                            'required_qty' => $template->required_qty,
                            'current_qty' => 0,
                            'has_expiry' => $template->has_expiry,
                            'sort_order' => $template->sort_order,
                        ]));

                    return $kit;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($typed) {
                    throw ValidationException::withMessages(['asset_code' => 'Another first aid kit already has that asset code.']);
                }

                if (++$attempts >= 5) {
                    throw $exception;
                }
            }
        }
    }

    protected function lock(HsFirstAidKit $kit): HsFirstAidKit
    {
        return HsFirstAidKit::query()->lockForUpdate()->findOrFail($kit->id);
    }

    protected function guardManage(User $actor, HsFirstAidKit $kit): void
    {
        abort_unless($this->scope->canManage($actor, $kit), 403, 'You may not change this kit.');
        $this->guardNotDecommissioned($kit);
    }

    protected function guardNotDecommissioned(HsFirstAidKit $kit): void
    {
        if ($kit->status === HsFirstAidKit::STATUS_DECOMMISSIONED) {
            throw ValidationException::withMessages(['status' => 'This kit has been decommissioned and can no longer be changed.']);
        }
    }

    protected function quantity(mixed $value, string $field, int $min): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > 9999) {
            throw ValidationException::withMessages([$field => 'Enter a whole number'.($min > 0 ? ' of at least '.$min : '').'.']);
        }

        return (int) $value;
    }

    protected function date(mixed $value, string $field): Carbon
    {
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Enter a valid date.']);
        }
    }
}
