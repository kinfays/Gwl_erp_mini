<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\FinSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The reminder options of ONE module for a location (the base of FinanceSettings and AuditSettings: two independent sets, each
 * changed only by its own manager). The defaults are config/gwl.php; a location's own value is an OVERRIDE row in fin_settings for its
 * books (Head Office, or a region without it, the same key as FinanceBooksVersion), and a value saved equal to its default is stored
 * as no row, so "default" keeps meaning "whatever config says". An on/off switch is stored as 0 / 1.
 *
 * Read here; written only by save(), which needs the module's `manage_reminders` permission (a Finance manager cannot touch the Audit
 * set and the other way round; super_admin may do both for the location they pick). The audit row holds the location ids and the
 * NAMES of the settings changed, never a value. Bound as a singleton so a save is seen by every reader of the request.
 */
abstract class ModuleReminderSettings
{
    /** @var array<string, array<string, int>> overrides by unit, kept for the request (the reminder commands ask per voucher) */
    protected array $memo = [];

    public function __construct(protected FinanceScope $scope) {}

    /** 'finance' or 'audit': the module whose screen, permission and audit rows these are. */
    abstract public function module(): string;

    /** The permission that changes them, for example finance.manage_reminders. */
    abstract public function permission(): string;

    /** @return array<string, array{label: string, help: string, type: string, unit: string, min: int, max: int, default: int}> */
    abstract public function definitions(): array;

    /** The location's own value, else the default. @param array{region_id: int|null, district_id: int|null} $location */
    public function value(array $location, string $key): int
    {
        $definition = $this->definitions()[$key] ?? throw new \InvalidArgumentException("Unknown {$this->module()} setting {$key}.");

        return $this->overrides($location)[$key] ?? $definition['default'];
    }

    /**
     * Each setting with its current value, its default and whether the location changed it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(array $location): array
    {
        $overrides = $this->overrides($location);

        return collect($this->definitions())->map(fn (array $definition, string $key) => $definition + [
            'value' => $overrides[$key] ?? $definition['default'],
            'overridden' => isset($overrides[$key]),
        ])->all();
    }

    /**
     * Saves a location's values (in the location the actor works in; super_admin names the district). Returns the values now in
     * force. Only this module's keys are read: another module's key in the array is ignored.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, int>
     */
    public function save(User $actor, array $values, ?int $districtId = null): array
    {
        abort_unless($this->scope->can($actor, $this->permission()), 403, 'You do not have permission to change these settings.');

        $location = $this->scope->recordingLocation($actor, $districtId);
        $unit = FinanceBooksVersion::unitKey($location);
        $definitions = $this->definitions();
        $clean = [];

        foreach ($values as $key => $value) {
            $definition = $definitions[$key] ?? null;

            if ($definition === null) {
                continue;
            }

            $clean[$key] = $this->clean($key, $definition, $value);
        }

        $saved = DB::transaction(function () use ($actor, $location, $unit, $clean, $definitions) {
            unset($this->memo[$unit]);
            $before = collect($this->all($location))->map(fn (array $row) => $row['value'])->all();

            foreach ($clean as $key => $value) {
                $rows = FinSetting::query()->where('unit', $unit)->where('key', $key)->lockForUpdate()->orderBy('id')->get();

                if ($value === $definitions[$key]['default']) {
                    $rows->each->delete();

                    continue;
                }

                $row = $rows->shift();
                $row ? $row->forceFill(['value' => $value, 'updated_by' => $actor->id])->save() : FinSetting::query()->create(['unit' => $unit, 'key' => $key, 'value' => $value, 'updated_by' => $actor->id]);
                $rows->each->delete();
            }

            unset($this->memo[$unit]);
            $after = collect($this->all($location))->map(fn (array $row) => $row['value'])->all();

            // Ids and the NAMES of what changed: never a value.
            AuditLog::record($this->module().'.settings_saved', $this->module(), 'fin_settings', null, null, null, [
                'region_id' => $location['region_id'],
                'district_id' => $location['district_id'],
                'changed' => array_keys(array_diff_assoc($after, $before)),
            ]);

            return $after;
        });

        // What counts as overdue may have changed, so every cached analytics payload of this location is stale.
        FinanceBooksVersion::touch($location);

        return $saved;
    }

    protected function clean(string $key, array $definition, mixed $value): int
    {
        if ($definition['type'] === 'bool') {
            if (is_bool($value) || in_array($value, [0, 1, '0', '1'], true)) {
                return (int) (bool) $value;
            }

            throw ValidationException::withMessages([$key => $definition['label'].' must be on or off.']);
        }

        if (! is_numeric($value) || (int) $value != $value || (int) $value < $definition['min'] || (int) $value > $definition['max']) {
            throw ValidationException::withMessages([$key => $definition['label'].' must be a whole number of days from '.$definition['min'].' to '.$definition['max'].'.']);
        }

        return (int) $value;
    }

    /** @return array<string, int> */
    protected function overrides(array $location): array
    {
        $unit = FinanceBooksVersion::unitKey($location);

        return $this->memo[$unit] ??= FinSetting::query()->where('unit', $unit)->whereIn('key', array_keys($this->definitions()))->pluck('value', 'key')->map(fn ($value) => (int) $value)->all();
    }

    /** @param array{region_id: int|null, district_id: int|null} $location */
    protected function days(string $label, string $help, string $config): array
    {
        return ['label' => $label, 'help' => $help, 'type' => 'days', 'unit' => 'days', 'min' => 1, 'max' => 365, 'default' => (int) config($config)];
    }

    protected function toggle(string $label, string $help, string $config): array
    {
        return ['label' => $label, 'help' => $help, 'type' => 'bool', 'unit' => '', 'min' => 0, 'max' => 1, 'default' => (int) (bool) config($config)];
    }
}
