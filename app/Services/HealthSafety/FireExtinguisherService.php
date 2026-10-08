<?php

namespace App\Services\HealthSafety;

use App\Models\HsExtinguisherCheck;
use App\Models\HsExtinguisherService;
use App\Models\HsFireExtinguisher;
use App\Models\Region;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every change to an extinguisher goes through here: who may do it, the rules for each action and the audit entry.
 * Checks and services are immutable rows; the item's dates and last check are updated in the same transaction that
 * writes them. A decommissioned unit takes no further changes.
 */
class FireExtinguisherService
{
    /** The private disk service certificates are kept on. */
    public const DISK = 'local';

    public const CERTIFICATE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(
        protected EquipmentScope $scope,
        protected EquipmentLocation $location,
        protected EquipmentAssetCodeGenerator $codes,
    ) {}

    // ------------------------------------------------------------------ the register

    /**
     * @param  array<string, mixed>  $data  asset_code (blank to generate), the unit's details and dates, and where it is
     */
    public function create(User $actor, array $data, bool $audit = true): HsFireExtinguisher
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_equipment'), 403, 'You may not add equipment.');

        $place = $this->location->resolve($actor, $data);
        $attributes = [...$this->validated($data), ...$place, 'status' => HsFireExtinguisher::STATUS_IN_SERVICE, 'created_by' => $actor->id];

        $extinguisher = $this->insert(Region::query()->findOrFail($place['region_id']), $attributes);

        if ($audit) {
            Audit::log('health_safety.extinguisher_saved', 'health_safety', 'hs_fire_extinguishers', $extinguisher->id, [
                'asset_code' => $extinguisher->asset_code,
                'created' => true,
            ]);
        }

        return $extinguisher;
    }

    /** @param  array<string, mixed>  $data */
    public function update(HsFireExtinguisher $extinguisher, User $actor, array $data): HsFireExtinguisher
    {
        return DB::transaction(function () use ($extinguisher, $actor, $data) {
            $locked = $this->lock($extinguisher);
            $this->guardManage($actor, $locked);

            $place = $this->location->resolve($actor, $data);
            $locked->fill([...$this->validated($data, $locked), ...$place])->save();

            Audit::log('health_safety.extinguisher_saved', 'health_safety', 'hs_fire_extinguishers', $locked->id, [
                'asset_code' => $locked->asset_code,
                'created' => false,
            ]);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ checks

    /**
     * The monthly visual check. The six points decide the result: pass only when all are yes, and a fail needs a note on
     * what was wrong. The unit's last check date and result are updated in the same transaction.
     *
     * @param  array<string, mixed>  $data  checked_on, in_place, accessible, seal_intact, pressure_ok, no_damage, signage_ok, notes
     */
    public function recordCheck(HsFireExtinguisher $extinguisher, User $actor, array $data): HsExtinguisherCheck
    {
        return DB::transaction(function () use ($extinguisher, $actor, $data) {
            $locked = $this->lock($extinguisher);

            abort_unless($this->scope->canCheck($actor, $locked), 403, 'You may not record a check on this extinguisher.');
            $this->guardNotDecommissioned($locked);

            $checkedOn = $this->requireDate($data['checked_on'] ?? today()->toDateString(), 'checked_on', future: false);

            $points = [];
            foreach (array_keys(HsFireExtinguisher::CHECK_POINTS) as $field) {
                $points[$field] = filter_var($data[$field] ?? false, FILTER_VALIDATE_BOOLEAN);
            }

            $result = in_array(false, $points, true) ? HsExtinguisherCheck::RESULT_FAIL : HsExtinguisherCheck::RESULT_PASS;
            $notes = filled($data['notes'] ?? null) ? trim($data['notes']) : null;

            if ($result === HsExtinguisherCheck::RESULT_FAIL && $notes === null) {
                throw ValidationException::withMessages(['notes' => 'Say what was wrong: at least one point failed.']);
            }

            $check = $locked->checks()->create([...$points, 'checked_on' => $checkedOn, 'checked_by' => $actor->id, 'result' => $result, 'notes' => $notes]);

            // A check recorded late (older than the last one) is kept in the history without moving the unit's latest.
            if ($locked->last_checked_on === null || $checkedOn->gte($locked->last_checked_on)) {
                $locked->forceFill(['last_checked_on' => $checkedOn, 'last_check_result' => $result])->save();
            }

            Audit::log('health_safety.extinguisher_checked', 'health_safety', 'hs_fire_extinguishers', $locked->id, [
                'asset_code' => $locked->asset_code,
                'result' => $result,
            ]);

            return $check;
        });
    }

    // ------------------------------------------------------------------ services

    /**
     * Record a service and bring the unit's dates up to date, in one transaction: last serviced; the expiry date when
     * a new one is given; the next service due (given, else serviced_on plus hs_extinguisher_service_months); and for a
     * hydrostatic test the last test and the next one (given, else plus hs_extinguisher_hydro_years, a pre-fill only). A
     * unit that was out for service goes back in service.
     *
     * A service dated before the unit's latest is added to the history and leaves the unit's dates alone.
     *
     * @param  array<string, mixed>  $data  serviced_on, service_type, vendor, new_expiry_date, next_service_due, next_hydro_test_due, notes
     */
    public function recordService(HsFireExtinguisher $extinguisher, User $actor, array $data, ?UploadedFile $certificate = null): HsExtinguisherService
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($extinguisher, $actor, $data, $certificate, &$storedPath) {
                $locked = $this->lock($extinguisher);
                $this->guardManage($actor, $locked);

                $servicedOn = $this->requireDate($data['serviced_on'] ?? null, 'serviced_on', future: false);
                $type = $data['service_type'] ?? null;

                if (! array_key_exists((string) $type, HsExtinguisherService::TYPES)) {
                    throw ValidationException::withMessages(['service_type' => 'Choose the kind of service.']);
                }

                $newExpiry = $this->optionalDate($data['new_expiry_date'] ?? null, 'new_expiry_date');
                $nextService = $this->optionalDate($data['next_service_due'] ?? null, 'next_service_due');
                $nextHydro = $this->optionalDate($data['next_hydro_test_due'] ?? null, 'next_hydro_test_due');

                foreach (['new_expiry_date' => $newExpiry, 'next_service_due' => $nextService, 'next_hydro_test_due' => $nextHydro] as $field => $date) {
                    if ($date !== null && $date->lt($servicedOn)) {
                        throw ValidationException::withMessages([$field => 'This date cannot be before the day it was serviced.']);
                    }
                }

                if ($certificate !== null) {
                    $storedPath = $this->storeCertificate($locked, $certificate);
                }

                $service = $locked->services()->create([
                    'serviced_on' => $servicedOn,
                    'service_type' => $type,
                    'vendor' => filled($data['vendor'] ?? null) ? trim($data['vendor']) : null,
                    'certificate_path' => $storedPath,
                    'certificate_name' => $certificate ? mb_substr($certificate->getClientOriginalName(), 0, 255) : null,
                    'certificate_mime' => $certificate?->getClientMimeType(),
                    'new_expiry_date' => $newExpiry,
                    'next_service_due' => $nextService,
                    'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                    'created_by' => $actor->id,
                ]);

                $latest = $locked->last_serviced_on === null || $servicedOn->gte($locked->last_serviced_on);

                if ($latest) {
                    $updates = [
                        'last_serviced_on' => $servicedOn,
                        'next_service_due' => $nextService ?? $servicedOn->copy()->addMonths((int) HealthSafetySettings::value('hs_extinguisher_service_months')),
                    ];

                    if ($newExpiry !== null) {
                        $updates['expiry_date'] = $newExpiry;
                    }

                    if ($type === HsExtinguisherService::TYPE_HYDRO_TEST) {
                        $updates['last_hydro_test_on'] = $servicedOn;
                        $updates['next_hydro_test_due'] = $nextHydro ?? $servicedOn->copy()->addYears((int) HealthSafetySettings::value('hs_extinguisher_hydro_years'));
                    }

                    if ($locked->status === HsFireExtinguisher::STATUS_OUT_FOR_SERVICE) {
                        $updates['status'] = HsFireExtinguisher::STATUS_IN_SERVICE;
                    }

                    $locked->forceFill($updates)->save();
                }

                Audit::log('health_safety.extinguisher_serviced', 'health_safety', 'hs_fire_extinguishers', $locked->id, [
                    'asset_code' => $locked->asset_code,
                    'service_type' => $type,
                    'serviced_on' => $servicedOn->toDateString(),
                ]);

                return $service;
            });
        } catch (\Throwable $exception) {
            // No orphaned certificate when the record could not be written.
            if ($storedPath !== null) {
                Storage::disk(self::DISK)->delete($storedPath);
            }

            throw $exception;
        }
    }

    // ------------------------------------------------------------------ status

    public function changeStatus(HsFireExtinguisher $extinguisher, User $actor, string $status): HsFireExtinguisher
    {
        return DB::transaction(function () use ($extinguisher, $actor, $status) {
            $locked = $this->lock($extinguisher);
            $this->guardManage($actor, $locked);

            if (! in_array($status, HsFireExtinguisher::SETTABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Choose in service, out for service or discharged. Decommissioning needs a reason.']);
            }

            $from = $locked->status;

            if ($from !== $status) {
                $locked->forceFill(['status' => $status])->save();

                Audit::log('health_safety.extinguisher_status_changed', 'health_safety', 'hs_fire_extinguishers', $locked->id, [
                    'asset_code' => $locked->asset_code,
                    'from' => $from,
                    'to' => $status,
                ]);
            }

            return $locked;
        });
    }

    public function decommission(HsFireExtinguisher $extinguisher, User $actor, string $reason): HsFireExtinguisher
    {
        return DB::transaction(function () use ($extinguisher, $actor, $reason) {
            $locked = $this->lock($extinguisher);
            $this->guardManage($actor, $locked);

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'Give the reason it is being decommissioned.']);
            }

            $this->guardNotDecommissioned($locked);

            $locked->forceFill([
                'status' => HsFireExtinguisher::STATUS_DECOMMISSIONED,
                'decommissioned_on' => today(),
                'decommission_reason' => mb_substr($reason, 0, 1000),
            ])->save();

            Audit::log('health_safety.extinguisher_decommissioned', 'health_safety', 'hs_fire_extinguishers', $locked->id, [
                'asset_code' => $locked->asset_code,
            ]);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validated(array $data, ?HsFireExtinguisher $existing = null): array
    {
        $validator = Validator::make($data, [
            'asset_code' => ['nullable', 'string', 'max:40', Rule::unique('hs_fire_extinguishers', 'asset_code')->ignore($existing?->id)],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'extinguisher_type' => ['required', Rule::in(array_keys(HsFireExtinguisher::TYPES))],
            'capacity' => ['nullable', 'string', 'max:50'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'manufactured_on' => ['nullable', 'date'],
            'location_detail' => ['nullable', 'string', 'max:255'],
            'responsible_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'expiry_date' => ['nullable', 'date'],
            'last_serviced_on' => ['nullable', 'date', 'before_or_equal:today'],
            'next_service_due' => ['nullable', 'date'],
            'last_hydro_test_on' => ['nullable', 'date', 'before_or_equal:today'],
            'next_hydro_test_due' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'extinguisher_type' => 'type',
            'asset_code' => 'asset code',
        ]);

        $validated = $validator->validate();

        $attributes = [];

        foreach ([
            'asset_code', 'serial_number', 'extinguisher_type', 'capacity', 'manufacturer', 'manufactured_on', 'location_detail',
            'responsible_employee_id', 'expiry_date', 'last_serviced_on', 'next_service_due', 'last_hydro_test_on', 'next_hydro_test_due', 'notes',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $validated[$field] ?? null;
                $attributes[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            }
        }

        return $attributes;
    }

    /**
     * Create the row, giving it a tag of its own when none was typed. A generated tag that collides with another
     * item's is asked for again; a typed one that does is the user's to change.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function insert(Region $region, array $attributes): HsFireExtinguisher
    {
        $typed = filled($attributes['asset_code'] ?? null);
        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(fn () => HsFireExtinguisher::query()->create([
                    ...$attributes,
                    'asset_code' => $typed ? $attributes['asset_code'] : $this->codes->nextExtinguisher($region),
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                if ($typed) {
                    throw ValidationException::withMessages(['asset_code' => 'Another extinguisher already has that asset code.']);
                }

                if (++$attempts >= 5) {
                    throw $exception;
                }
            }
        }
    }

    protected function lock(HsFireExtinguisher $extinguisher): HsFireExtinguisher
    {
        return HsFireExtinguisher::query()->lockForUpdate()->findOrFail($extinguisher->id);
    }

    protected function guardManage(User $actor, HsFireExtinguisher $extinguisher): void
    {
        abort_unless($this->scope->canManage($actor, $extinguisher), 403, 'You may not change this extinguisher.');
        $this->guardNotDecommissioned($extinguisher);
    }

    protected function guardNotDecommissioned(HsFireExtinguisher $extinguisher): void
    {
        if ($extinguisher->status === HsFireExtinguisher::STATUS_DECOMMISSIONED) {
            throw ValidationException::withMessages(['status' => 'This extinguisher has been decommissioned and can no longer be changed.']);
        }
    }

    protected function requireDate(mixed $value, string $field, bool $future): Carbon
    {
        $date = $this->optionalDate($value, $field);

        if ($date === null) {
            throw ValidationException::withMessages([$field => 'Enter the date.']);
        }

        if (! $future && $date->gt(today())) {
            throw ValidationException::withMessages([$field => 'The date cannot be in the future.']);
        }

        return $date;
    }

    protected function optionalDate(mixed $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Enter a valid date.']);
        }
    }

    protected function storeCertificate(HsFireExtinguisher $extinguisher, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $maxBytes = (int) config('gwl.hs_equipment_attachment_max_mb') * 1024 * 1024;

        if (! in_array($extension, self::CERTIFICATE_EXTENSIONS, true) || $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['certificate' => 'The certificate must be a PDF, JPG or PNG of at most '.(int) config('gwl.hs_equipment_attachment_max_mb').' MB.']);
        }

        $path = $file->store('health_safety/equipment/'.$extinguisher->id, self::DISK);

        if (! $path) {
            throw ValidationException::withMessages(['certificate' => 'The certificate could not be saved. Try again.']);
        }

        return $path;
    }

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
