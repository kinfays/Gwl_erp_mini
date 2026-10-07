<?php

namespace App\Services\Commercial;

use App\Models\CommercialSetting;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The Commercial targets and thresholds an administrator may change.
 *
 * config/gwl.php (and .env) hold the defaults. An edited value is stored as an OVERRIDE row and laid over the config at
 * boot (applyToConfig()), so every analysis, screen and export keeps reading config('gwl.commercial_*') and sees the
 * edited value without being touched. A value saved equal to its default is stored as no override at all, so "default"
 * keeps meaning "whatever config / .env says".
 *
 * The targets are placeholders until the Commercial team confirms official ones (design 10, question 5).
 */
class CommercialSettings
{
    /**
     * The values config('gwl.*') had BEFORE any override was laid over it, for the editable settings: the defaults.
     * Taken from the live config, never from a `require` of config/gwl.php: under `php artisan config:cache` .env is not
     * loaded and env() outside the config files returns null, so re-reading the file would give the hard-coded fallbacks
     * instead of what .env said. This class must be a container singleton (AppServiceProvider) so the copy is kept.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $pristine = null;

    /** True once overrides have been laid over the config, after which the live config is no longer the default. */
    protected bool $applied = false;

    public const CACHE_KEY = 'commercial_settings:v1';

    public const GROUPS = [
        'targets' => ['title' => 'Targets', 'description' => 'What the screens measure the reading and collection figures against. Placeholders until the Commercial team confirms official targets.'],
        'readers' => ['title' => 'Reader analysis', 'description' => 'When a reader is judged an outlier or an unusual workload.'],
        'scorecard' => ['title' => 'Reader scorecard weights', 'description' => 'How much volume, skip rate and consistency count in the indicative score. They must add up to 1.'],
        'exceptions' => ['title' => 'Billing route exceptions', 'description' => 'When a route is flagged on the Billing Exceptions tab.'],
        'reminders' => ['title' => 'Upload reminders', 'description' => 'When officers are told an upload is overdue.'],
        'exports' => ['title' => 'Exports', 'description' => 'Limits on Excel and PDF files.'],
    ];

    /**
     * Every editable setting: the config key under gwl.*, how it is shown and the limits a saved value must respect.
     *
     * @return array<string, array{group: string, label: string, help: string, type: string, min: float|int|null, max: float|int|null, unit: string}>
     */
    public static function definitions(): array
    {
        return [
            'commercial_target_skip_rate_pct' => ['group' => 'targets', 'label' => 'Skip-rate target', 'help' => 'The skip rate should be at or below this.', 'type' => 'float', 'min' => 0, 'max' => 100, 'unit' => '%'],
            'commercial_target_coverage_pct' => ['group' => 'targets', 'label' => 'Coverage target', 'help' => 'Visited ÷ verified strength should be at or above this.', 'type' => 'float', 'min' => 0, 'max' => 100, 'unit' => '%'],
            'commercial_target_collection_pct' => ['group' => 'targets', 'label' => 'Cash collection target', 'help' => 'Payments ÷ billing should be at or above this (it can exceed 100% when arrears are recovered).', 'type' => 'float', 'min' => 0, 'max' => 200, 'unit' => '%'],

            'commercial_min_visits_for_outlier' => ['group' => 'readers', 'label' => 'Minimum visits per active month to be judged', 'help' => 'A reader with fewer is not ranked or flagged on skip rate: too little to judge.', 'type' => 'int', 'min' => 1, 'max' => 100000, 'unit' => 'visits'],
            'commercial_outlier_zscore' => ['group' => 'readers', 'label' => 'Outlier threshold', 'help' => 'Standard deviations above the other readers\' skip rate. A flag needs at least 6 eligible readers at 2.0.', 'type' => 'float', 'min' => 0.5, 'max' => 10, 'unit' => 'σ'],
            'commercial_min_readers_for_district_figures' => ['group' => 'readers', 'label' => 'Minimum readers before a district\'s reading figures are shown', 'help' => 'Users who may not see individual readers see no district reading figures below this.', 'type' => 'int', 'min' => 1, 'max' => 50, 'unit' => 'readers'],
            'commercial_workload_low_pct' => ['group' => 'readers', 'label' => 'Low workload below', 'help' => 'Percent of the median visits of the active readers.', 'type' => 'int', 'min' => 1, 'max' => 99, 'unit' => '%'],
            'commercial_workload_high_pct' => ['group' => 'readers', 'label' => 'High workload above', 'help' => 'Percent of the median visits of the active readers.', 'type' => 'int', 'min' => 101, 'max' => 1000, 'unit' => '%'],

            'commercial_scorecard_weights.volume' => ['group' => 'scorecard', 'label' => 'Volume weight', 'help' => 'More visits scores higher.', 'type' => 'float', 'min' => 0, 'max' => 1, 'unit' => ''],
            'commercial_scorecard_weights.skip' => ['group' => 'scorecard', 'label' => 'Skip-rate weight', 'help' => 'A lower skip rate scores higher.', 'type' => 'float', 'min' => 0, 'max' => 1, 'unit' => ''],
            'commercial_scorecard_weights.consistency' => ['group' => 'scorecard', 'label' => 'Consistency weight', 'help' => 'Steadier monthly visits score higher.', 'type' => 'float', 'min' => 0, 'max' => 1, 'unit' => ''],

            'commercial_exception_min_customers' => ['group' => 'exceptions', 'label' => 'Minimum customers behind a percentage', 'help' => 'A route needs at least this many customers before it can be flagged on a percentage.', 'type' => 'int', 'min' => 1, 'max' => 1000, 'unit' => 'customers'],
            'commercial_exception_high_unbilled_pct' => ['group' => 'exceptions', 'label' => 'High unbilled above', 'help' => 'Unbilled ÷ (billed + unbilled).', 'type' => 'float', 'min' => 0, 'max' => 100, 'unit' => '%'],
            'commercial_exception_high_estimation_pct' => ['group' => 'exceptions', 'label' => 'High estimation above', 'help' => 'Estimated bills ÷ customers billed.', 'type' => 'float', 'min' => 0, 'max' => 100, 'unit' => '%'],
            'commercial_exception_credit_amount' => ['group' => 'exceptions', 'label' => 'Heavy credit at or beyond', 'help' => 'A closing balance at or below minus this amount (GH¢) is a heavy credit.', 'type' => 'float', 'min' => 0.01, 'max' => 10000000, 'unit' => 'GH¢'],

            'commercial_reminders_enabled' => ['group' => 'reminders', 'label' => 'Send upload reminders', 'help' => 'Tell officers in-app when an upload is overdue. The overdue badge on the Summary page shows either way.', 'type' => 'bool', 'min' => null, 'max' => null, 'unit' => ''],
            'commercial_reminder_reading_days' => ['group' => 'reminders', 'label' => 'Meter reading overdue after', 'help' => 'Days since the last meter reading upload for a region.', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'days'],
            'commercial_reminder_billing_days' => ['group' => 'reminders', 'label' => 'Billing overdue after', 'help' => 'Days since the last billing upload for a region.', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'days'],
            'commercial_reminder_repeat_days' => ['group' => 'reminders', 'label' => 'Remind again after', 'help' => 'An overdue upload is reminded at most this often.', 'type' => 'int', 'min' => 1, 'max' => 90, 'unit' => 'days'],

            'commercial_export_max_rows' => ['group' => 'exports', 'label' => 'Most rows in one export', 'help' => 'Over this the user is asked to narrow the filters.', 'type' => 'int', 'min' => 100, 'max' => 20000, 'unit' => 'rows'],
            'commercial_export_pdf_max_rows' => ['group' => 'exports', 'label' => 'Most rows in one PDF', 'help' => 'PDFs are heavy to build, so they have a lower limit than Excel. Over it the user is asked to narrow the filters or use Excel.', 'type' => 'int', 'min' => 50, 'max' => 3000, 'unit' => 'rows'],
        ];
    }

    /**
     * The value config/gwl.php (and .env) give a setting, ignoring any override: from the pristine copy, or, before one has
     * been taken and while nothing has been laid over the config, from the live config.
     */
    public function default(string $key): mixed
    {
        if ($this->pristine === null && ! $this->applied) {
            $this->capturePristine();
        }

        return $this->cast($key, $this->pristine !== null && array_key_exists($key, $this->pristine) ? $this->pristine[$key] : config('gwl.'.$key));
    }

    /** Keeps a copy of the editable settings as the config has them now. Only the first call counts. */
    public function capturePristine(): void
    {
        if ($this->pristine !== null) {
            return;
        }

        $this->pristine = [];

        foreach (array_keys(self::definitions()) as $key) {
            $this->pristine[$key] = config('gwl.'.$key);
        }
    }

    /** Throws the copy away (tests; or a config that was changed on purpose after boot). */
    public function forgetPristine(): void
    {
        $this->pristine = null;
        $this->applied = false;
    }

    /** @return array<string, string> name => stored value, for the settings that have been edited */
    public function overrides(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => CommercialSetting::query()->pluck('value', 'name')->all());
    }

    /** The value in force: the override when there is one, otherwise the default. */
    public function current(string $key): mixed
    {
        $overrides = $this->overrides();

        return array_key_exists($key, $overrides) ? $this->cast($key, $overrides[$key]) : $this->default($key);
    }

    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->overrides());
    }

    /**
     * Lays the overrides over config('gwl.*'). Called at boot (while the module is enabled) and after every save; safe
     * to call before the table exists (a fresh install, or while migrating).
     */
    public function applyToConfig(): void
    {
        $this->capturePristine();   // before anything is laid over the config

        try {
            $overrides = $this->overrides();
        } catch (\Throwable) {
            return;
        }

        $this->applied = true;

        foreach ($overrides as $name => $value) {
            if (isset(self::definitions()[$name])) {
                config()->set('gwl.'.$name, $this->cast($name, $value));
            }
        }
    }

    /**
     * Saves the whole form. Values equal to their default are stored as no override; everything else is validated
     * against the limits above (and the cross-checks: low workload below high, weights adding up to 1).
     *
     * @param  array<string, mixed>  $values  key => value for every setting (or the ones being changed)
     * @return array<string, array{from: mixed, to: mixed}> what changed
     *
     * @throws ValidationException
     */
    public function save(array $values, ?int $actorId = null): array
    {
        $definitions = self::definitions();
        $values = array_intersect_key($values, $definitions);

        // Dots in a key are array paths for the validator, so validate under safe names.
        $safe = [];
        $rules = [];
        $labels = [];

        foreach ($values as $key => $value) {
            $name = str_replace('.', '__', $key);
            $safe[$name] = $value;
            $definition = $definitions[$key];
            $rules[$name] = match ($definition['type']) {
                'bool' => ['required', 'boolean'],
                'int' => ['required', 'integer', 'min:'.$definition['min'], 'max:'.$definition['max']],
                default => ['required', 'numeric', 'min:'.$definition['min'], 'max:'.$definition['max']],
            };
            $labels[$name] = strtolower($definition['label']);
        }

        $validator = Validator::make($safe, $rules, [], $labels);
        $merged = fn (string $key) => array_key_exists($key, $values) ? $this->cast($key, $values[$key]) : $this->current($key);

        $validator->after(function ($validator) use ($merged): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($merged('commercial_workload_low_pct') >= $merged('commercial_workload_high_pct')) {
                $validator->errors()->add('commercial_workload_low_pct', 'The low workload percentage must be below the high one.');
            }

            $sum = $merged('commercial_scorecard_weights.volume') + $merged('commercial_scorecard_weights.skip') + $merged('commercial_scorecard_weights.consistency');

            if (abs($sum - 1.0) > 0.01) {
                $validator->errors()->add('commercial_scorecard_weights.volume', 'The three scorecard weights must add up to 1 (they add up to '.rtrim(rtrim(number_format($sum, 3), '0'), '.').').');
            }
        });

        if ($validator->fails()) {
            // Report under the real key so the screen can show the message beside the field.
            $errors = [];

            foreach ($validator->errors()->messages() as $name => $messages) {
                $errors[str_replace('__', '.', $name)] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $changes = DB::transaction(function () use ($values, $actorId): array {
            $changes = [];

            foreach ($values as $key => $value) {
                $new = $this->cast($key, $value);
                $before = $this->current($key);
                $isDefault = $this->same($key, $new, $this->default($key));

                if ($isDefault) {
                    CommercialSetting::query()->where('name', $key)->delete();
                } else {
                    CommercialSetting::query()->updateOrCreate(['name' => $key], ['value' => $this->store($key, $new), 'updated_by' => $actorId]);
                }

                if (! $this->same($key, $new, $before)) {
                    $changes[$key] = ['from' => $before, 'to' => $new];
                }
            }

            return $changes;
        });

        $this->refresh();

        if ($changes !== []) {
            Audit::log(
                action: 'commercial.settings_changed',
                module: Permission::MODULE_COMMERCIAL,
                targetType: CommercialSetting::class,
                metadata: ['changes' => $changes]
            );
        }

        return $changes;
    }

    /**
     * Puts the given settings (or every one) back to their default.
     *
     * @param  list<string>|null  $keys
     * @return list<string> the settings that had been edited
     */
    public function reset(?array $keys = null): array
    {
        $names = $keys === null ? array_keys(self::definitions()) : array_values(array_intersect($keys, array_keys(self::definitions())));
        $edited = CommercialSetting::query()->whereIn('name', $names)->pluck('name')->all();

        if ($edited === []) {
            return [];
        }

        $before = [];

        foreach ($edited as $name) {
            $before[$name] = $this->current($name);
        }

        CommercialSetting::query()->whereIn('name', $edited)->delete();
        $this->refresh();

        // Config was overwritten by the overrides; put the defaults back for this request too.
        foreach ($edited as $name) {
            config()->set('gwl.'.$name, $this->default($name));
        }

        Audit::log(
            action: 'commercial.settings_reset',
            module: Permission::MODULE_COMMERCIAL,
            targetType: CommercialSetting::class,
            metadata: ['reset' => array_map(fn ($name) => ['from' => $before[$name], 'to' => $this->default($name)], array_combine($edited, $edited))]
        );

        return $edited;
    }

    /** Forgets the cache and re-applies the overrides. */
    protected function refresh(): void
    {
        $this->capturePristine();
        $this->applied = true;
        Cache::forget(self::CACHE_KEY);

        foreach (array_keys(self::definitions()) as $key) {
            config()->set('gwl.'.$key, $this->current($key));
        }
    }

    protected function cast(string $key, mixed $value): mixed
    {
        return match (self::definitions()[$key]['type'] ?? 'string') {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    protected function store(string $key, mixed $value): string
    {
        return match (self::definitions()[$key]['type']) {
            'bool' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    protected function same(string $key, mixed $a, mixed $b): bool
    {
        return self::definitions()[$key]['type'] === 'float' ? abs((float) $a - (float) $b) < 0.0000001 : $a === $b;
    }
}
