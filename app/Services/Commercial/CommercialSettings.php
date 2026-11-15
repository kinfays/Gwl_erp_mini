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
        'readers' => ['title' => 'CCA analysis', 'description' => 'When a CCA is judged an outlier or an unusual workload.'],
        'reading_routes' => ['title' => 'Reading by route', 'description' => 'The reading report by route and CCA: who is ranked, who is an outlier among the CCAs of their district, and when a route\'s verified strength is far from the customer list.'],
        'scorecard' => ['title' => 'CCA scorecard weights', 'description' => 'How much volume, skip rate and consistency count in the indicative score. They must add up to 1.'],
        'exceptions' => ['title' => 'Billing route exceptions', 'description' => 'When a route is flagged on the Billing Exceptions tab.'],
        'reminders' => ['title' => 'Upload reminders', 'description' => 'When officers are told an upload is overdue.'],
        'exports' => ['title' => 'Exports', 'description' => 'Limits on Excel and PDF files.'],
        'imports' => ['title' => 'Imports and reconciliation', 'description' => 'How strictly an upload is checked against its own totals, and which accounts are never imported.'],
        'routes' => ['title' => 'Routes and districts', 'description' => 'Which system decides the district of a route when billing / reading and the customer list disagree.'],
        'customers' => ['title' => 'Customer list', 'description' => 'Thresholds, retention and export limits for the customer list. Category groups, status meanings and each district\'s upload cadence are on their own screen.'],
        // Phase 7. Has its own screen (commercial.manage_sms_settings), so the general settings screen leaves this group out.
        'sms' => ['title' => 'SMS to customers', 'description' => 'Limits and defaults for SMS campaigns to Accra West customers. The provider key, sender IDs and the region lock are in the server settings (.env), not here.'],
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

            'commercial_min_visits_for_outlier' => ['group' => 'readers', 'label' => 'Minimum visits per active month to be judged', 'help' => 'A CCA with fewer is not ranked or flagged on skip rate: too little to judge.', 'type' => 'int', 'min' => 1, 'max' => 100000, 'unit' => 'visits'],
            'commercial_outlier_zscore' => ['group' => 'readers', 'label' => 'Outlier threshold', 'help' => 'Standard deviations above the other CCAs\' skip rate. A flag needs at least 6 eligible CCAs at 2.0.', 'type' => 'float', 'min' => 0.5, 'max' => 10, 'unit' => 'σ'],
            'commercial_min_readers_for_district_figures' => ['group' => 'readers', 'label' => 'Minimum CCAs before a district\'s reading figures are shown', 'help' => 'Users who may not see individual CCAs see no district reading figures below this.', 'type' => 'int', 'min' => 1, 'max' => 50, 'unit' => 'readers'],
            'commercial_min_visits_for_ranking' => ['group' => 'reading_routes', 'label' => 'Minimum visits for a CCA to be ranked', 'help' => 'On the reading-by-route CCA scorecard a CCA is ranked on skip rate only with at least this many visits in the range, so a CCA with a handful of visits is not ranked on noise. The same minimum decides who counts as eligible for the district outlier check.', 'type' => 'int', 'min' => 1, 'max' => 100000, 'unit' => 'visits'],
            'commercial_skip_outlier_sigma' => ['group' => 'reading_routes', 'label' => 'Skip-rate outlier threshold', 'help' => 'Standard deviations above the mean skip rate of the eligible CCAs of the same district. A district needs at least 5 eligible CCAs to be checked.', 'type' => 'float', 'min' => 0.5, 'max' => 10, 'unit' => 'σ'],
            'commercial_strength_mismatch_pct' => ['group' => 'reading_routes', 'label' => 'Verified strength far from the customer list by more than', 'help' => 'A route is listed when its verified strength differs from its metered accounts (working or faulty meter) in the newest customer list by more than this percent of the metered accounts.', 'type' => 'float', 'min' => 1, 'max' => 100, 'unit' => '%'],
            'commercial_workload_low_pct' => ['group' => 'readers', 'label' => 'Low workload below', 'help' => 'Percent of the median visits of the active CCAs.', 'type' => 'int', 'min' => 1, 'max' => 99, 'unit' => '%'],
            'commercial_workload_high_pct' => ['group' => 'readers', 'label' => 'High workload above', 'help' => 'Percent of the median visits of the active CCAs.', 'type' => 'int', 'min' => 101, 'max' => 1000, 'unit' => '%'],

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
            'commercial_customer_count_change_warn_pct' => ['group' => 'customers', 'label' => 'Warn when a file moves the customer count by more than', 'help' => 'Against the district\'s current count. A partial export looks like a big drop.', 'type' => 'float', 'min' => 1, 'max' => 100, 'unit' => '%'],
            'commercial_customer_outlier_multiple' => ['group' => 'customers', 'label' => 'Consumption outlier at', 'help' => 'A billing customer whose average consumption is above this many times the median of its category.', 'type' => 'float', 'min' => 1.5, 'max' => 50, 'unit' => '× median'],
            'commercial_customer_history_high_multiple' => ['group' => 'customers', 'label' => 'Consumption spike at', 'help' => 'An account whose average consumption is above this many times the mean of its own earlier months.', 'type' => 'float', 'min' => 1.1, 'max' => 20, 'unit' => '× its own mean'],
            'commercial_customer_history_low_multiple' => ['group' => 'customers', 'label' => 'Consumption drop at', 'help' => 'An account whose average consumption is below this share of the mean of its own earlier months (but not zero).', 'type' => 'float', 'min' => 0.05, 'max' => 0.95, 'unit' => '× its own mean'],
            'commercial_customer_history_min_months' => ['group' => 'customers', 'label' => 'Earlier months needed for a baseline', 'help' => 'An account is compared with its own history only once this many earlier months are stored.', 'type' => 'int', 'min' => 2, 'max' => 12, 'unit' => 'months'],
            'commercial_customer_undo_keep_batches' => ['group' => 'customers', 'label' => 'Uploads per district that can be voided exactly', 'help' => 'The rollback data of this many newest uploads per district is kept; older uploads cannot be undone (upload a corrected file instead). More keeps more data.', 'type' => 'int', 'min' => 1, 'max' => 4, 'unit' => 'uploads'],
            'commercial_customer_contact_retention_days' => ['group' => 'customers', 'label' => 'Delete contact details of accounts missing from the files for', 'help' => 'Personal data (name, address, phones, e-mail) of an account no file has listed for this many days is deleted. 0 = never delete.', 'type' => 'int', 'min' => 0, 'max' => 3650, 'unit' => 'days'],
            'commercial_customer_reminder_weekly_days' => ['group' => 'customers', 'label' => 'Weekly customer list overdue after', 'help' => 'For districts expected to upload weekly.', 'type' => 'int', 'min' => 1, 'max' => 60, 'unit' => 'days'],
            'commercial_customer_reminder_monthly_days' => ['group' => 'customers', 'label' => 'Monthly customer list overdue after', 'help' => 'For districts expected to upload monthly.', 'type' => 'int', 'min' => 1, 'max' => 120, 'unit' => 'days'],
            'commercial_customer_export_max_rows' => ['group' => 'customers', 'label' => 'Most rows in one customer export', 'help' => 'Over it the export is refused (when the size is known) or cut off with a note.', 'type' => 'int', 'min' => 1000, 'max' => 500000, 'unit' => 'rows'],
            'commercial_customer_export_queue_threshold' => ['group' => 'customers', 'label' => 'Build in the background above', 'help' => 'A customer export bigger than this (or of unknown size) is built in the background and the user is notified.', 'type' => 'int', 'min' => 1000, 'max' => 200000, 'unit' => 'rows'],

            'commercial_sms_low_balance' => ['group' => 'sms', 'label' => 'Warn when SMS credits fall below', 'help' => 'The balance banner and the daily notice to the officers start here.', 'type' => 'int', 'min' => 0, 'max' => 1000000, 'unit' => 'credits'],
            'commercial_sms_max_recipients' => ['group' => 'sms', 'label' => 'Most recipients in one campaign', 'help' => 'After numbers shared by several accounts are counted once. A larger audience is refused.', 'type' => 'int', 'min' => 1, 'max' => 500000, 'unit' => 'recipients'],
            'commercial_sms_max_selected' => ['group' => 'sms', 'label' => 'Most customers in a hand-picked or pasted list', 'help' => 'Ticked customers and pasted account numbers.', 'type' => 'int', 'min' => 1, 'max' => 10000, 'unit' => 'customers'],
            'commercial_sms_chunk_size' => ['group' => 'sms', 'label' => 'Messages handed to the provider at a time', 'help' => "Smaller is gentler on the provider's rate limit and on a restart.", 'type' => 'int', 'min' => 1, 'max' => 1000, 'unit' => 'messages'],
            'commercial_sms_chunk_delay_seconds' => ['group' => 'sms', 'label' => 'Pause between those groups', 'help' => "Protects the provider's rate limit.", 'type' => 'int', 'min' => 0, 'max' => 60, 'unit' => 'seconds'],
            'commercial_sms_unknown_after_minutes' => ['group' => 'sms', 'label' => 'Mark a message "unknown" when it has not been saved after', 'help' => 'A worker that died after calling the provider leaves a message claimed; after this long it shows as unknown and is never sent again by itself.', 'type' => 'int', 'min' => 1, 'max' => 1440, 'unit' => 'minutes'],
            'commercial_sms_poll_days' => ['group' => 'sms', 'label' => 'Look for delivery reports for campaigns started in the last', 'help' => 'Older campaigns are no longer asked about.', 'type' => 'int', 'min' => 1, 'max' => 30, 'unit' => 'days'],
            'commercial_sms_recipient_retention_days' => ['group' => 'sms', 'label' => 'Delete who-got-what rows of finished campaigns after', 'help' => 'The campaign totals stay. 0 = keep for ever.', 'type' => 'int', 'min' => 0, 'max' => 3650, 'unit' => 'days'],
            'commercial_sms_debtors_min' => ['group' => 'sms', 'label' => 'Debtors: minimum owed (the starting value)', 'help' => 'The send screen starts with this and the sender can change it for each campaign.', 'type' => 'float', 'min' => 0, 'max' => 1000000, 'unit' => 'GH¢'],
            'commercial_sms_most_valuable_top_n' => ['group' => 'sms', 'label' => 'Most valuable customers: top N (the starting value)', 'help' => 'Billing accounts with the highest last bill. An assumption to be confirmed.', 'type' => 'int', 'min' => 1, 'max' => 100000, 'unit' => 'customers'],
            'commercial_sms_most_valuable_top_pct' => ['group' => 'sms', 'label' => 'Most valuable customers: top percent (the starting value)', 'help' => 'Used when the sender chooses a percentage instead of a number.', 'type' => 'float', 'min' => 0.1, 'max' => 100, 'unit' => '%'],

            'commercial_route_district_authority' => ['group' => 'routes', 'label' => 'District of a route is decided by', 'help' => 'When two routes share a code in one region, the one whose district comes from this source is the canonical route and the other becomes its alias.', 'type' => 'enum', 'options' => ['billing' => 'The billing / reading label', 'customer_list' => 'The customer list'], 'min' => null, 'max' => null, 'unit' => ''],
            'commercial_excluded_staff_ids' => ['group' => 'imports', 'label' => 'Staff IDs never imported', 'help' => 'Separated by commas. A reading row for one of these IDs (and any account named "System Administrator") is skipped, shown in the preview as excluded and never stored.', 'type' => 'list', 'min' => null, 'max' => null, 'unit' => ''],
            'commercial_reconcile_max_route_failures_pct' => ['group' => 'imports', 'label' => 'Block a file when more routes than this fail their totals', 'help' => 'A route whose rows disagree with its own TOTALS row is flagged and the file still imports, until more than this share of its routes fail.', 'type' => 'float', 'min' => 0, 'max' => 100, 'unit' => '% of routes'],
            'commercial_reconcile_grand_total_tolerance_pct' => ['group' => 'imports', 'label' => 'Block a file when its grand total is off by more than', 'help' => 'The rows against the report\'s own grand total.', 'type' => 'float', 'min' => 0, 'max' => 100, 'unit' => '%'],

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

    /** The definitions of every group except the given one (the general settings screen leaves SMS to its own screen). @return array<string, array<string, mixed>> */
    public static function definitionsWithout(string $group): array
    {
        return array_filter(self::definitions(), fn (array $definition) => $definition['group'] !== $group);
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
                'enum' => ['required', 'in:'.implode(',', array_keys($definition['options']))],
                'list' => ['nullable', 'string', 'max:500'],
                'bool' => ['required', 'boolean'],
                'int' => ['required', 'integer', 'min:'.$definition['min'], 'max:'.$definition['max']],
                default => ['required', 'numeric', 'min:'.$definition['min'], 'max:'.$definition['max']],
            };
            $labels[$name] = strtolower($definition['label']);
        }

        $validator = Validator::make($safe, $rules, [], $labels);
        $merged = fn (string $key) => array_key_exists($key, $values) ? $this->cast($key, $values[$key]) : $this->current($key);

        $validator->after(function ($validator) use ($merged, $values): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->cast('commercial_excluded_staff_ids', $values['commercial_excluded_staff_ids'] ?? []) as $id) {
                if (! preg_match('/^[A-Za-z0-9._-]{1,20}$/', (string) $id)) {
                    $validator->errors()->add('commercial_excluded_staff_ids', 'A staff ID is letters, digits, dots, dashes or underscores, up to 20 characters.');

                    break;
                }
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
            'list' => array_values(array_unique(array_filter(array_map(fn ($item) => trim((string) $item), is_array($value) ? $value : explode(',', (string) $value)), fn (string $item) => $item !== ''))),
            'enum' => (string) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    protected function store(string $key, mixed $value): string
    {
        return match (self::definitions()[$key]['type']) {
            'list' => implode(',', (array) $value),
            'bool' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    protected function same(string $key, mixed $a, mixed $b): bool
    {
        return match (self::definitions()[$key]['type']) {
            'float' => abs((float) $a - (float) $b) < 0.0000001,
            'list' => (array) $a === (array) $b,
            default => $a === $b,
        };
    }
}
