<?php

namespace App\Services\HealthSafety;

use App\Models\HsSetting;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE accessor for the Health & Safety values an administrator may change. A value is resolved as: the saved value, else
 * the default in config/gwl.php (which .env feeds). Every read of these keys anywhere in the module goes through get() (or
 * the hs_setting() helper); nothing else reads config('gwl.hs_...') for them, so there is one place that decides the
 * current value.
 *
 * A saved value is an OVERRIDE: one equal to its default is stored as no row at all, so "default" keeps meaning "whatever
 * config / .env says", and a test (or a deployment) that sets the config still wins when nothing has been saved.
 *
 * Unlike the Commercial module's settings, nothing is laid over config() at boot: callers ask this class. The saved rows
 * are read once per instance (a container singleton), not cached across requests, so a save is seen at once everywhere.
 */
class HealthSafetySettings
{
    public const GROUPS = [
        'equipment' => ['title' => 'Equipment dates', 'description' => 'When an extinguisher or kit is "expiring" or "check overdue", and how far ahead its next service and test are set.'],
        'incidents' => ['title' => 'Incident follow-up', 'description' => 'How long a report may wait before it is chased.'],
        'alerts' => ['title' => 'Alerts', 'description' => 'When the daily command tells people about a due date. Negative numbers are days overdue.'],
        'ppe' => ['title' => 'PPE', 'description' => 'How far back a PPE issue or return may be dated.'],
        'exports' => ['title' => 'Exports', 'description' => 'The limit on one Excel or PDF file.'],
        'workflow' => ['title' => 'Workflow and the report form', 'description' => 'Approval rules and the text on the report form.'],
    ];

    /**
     * Every editable setting: how it is shown, what it affects and the limits a saved value must respect. type is one of
     * int, bool, text or intlist (a list of distinct integers).
     *
     * @return array<string, array{group: string, label: string, help: string, type: string, min: int|null, max: int|null, unit: string}>
     */
    public static function definitions(): array
    {
        return [
            'hs_expiry_warning_days' => ['group' => 'equipment', 'label' => 'Warning window', 'help' => 'An item is "expiring" or "due soon" this many days before its date. Also the "replacement due" window for PPE.', 'type' => 'int', 'min' => 2, 'max' => 365, 'unit' => 'days'],
            'hs_expiry_critical_days' => ['group' => 'equipment', 'label' => 'Critical window', 'help' => 'The "critical" bucket of the expiry register and the Overview counts. It must be shorter than the warning window.', 'type' => 'int', 'min' => 1, 'max' => 364, 'unit' => 'days'],
            'hs_check_interval_days' => ['group' => 'equipment', 'label' => 'Check interval', 'help' => 'A unit not checked for this long shows as "check overdue".', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'days'],
            'hs_extinguisher_service_months' => ['group' => 'equipment', 'label' => 'Extinguisher service every', 'help' => 'Used to suggest the next service date when a service is recorded.', 'type' => 'int', 'min' => 1, 'max' => 60, 'unit' => 'months'],
            'hs_extinguisher_hydro_years' => ['group' => 'equipment', 'label' => 'Hydrostatic test every', 'help' => 'Used to suggest the next test date. It depends on the extinguisher type: confirm with the EHS department.', 'type' => 'int', 'min' => 1, 'max' => 30, 'unit' => 'years'],

            'hs_ack_hours' => ['group' => 'incidents', 'label' => 'Acknowledge a report within', 'help' => 'Past this, a new report is "not acknowledged" and the officers are reminded; at twice this the manager is told.', 'type' => 'int', 'min' => 1, 'max' => 720, 'unit' => 'hours'],
            'hs_investigation_due_days' => ['group' => 'incidents', 'label' => 'Finish an investigation within', 'help' => 'Counted from acknowledgement. Past it the officer is reminded; a week later the manager is told.', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'days'],

            'hs_alert_thresholds' => ['group' => 'alerts', 'label' => 'Equipment alert thresholds', 'help' => 'Days before an extinguisher, kit-item or PPE date at which people are told, highest first. Negative = overdue. Example: 60, 30, 7, 0, -7, -30.', 'type' => 'intlist', 'min' => -365, 'max' => 365, 'unit' => 'days'],
            'hs_action_alert_thresholds' => ['group' => 'alerts', 'label' => 'Action reminder thresholds', 'help' => 'Days before an action\'s due date at which the assignee is reminded. Negative = overdue (the officer is told too). Example: 3, 0, -7.', 'type' => 'intlist', 'min' => -365, 'max' => 365, 'unit' => 'days'],

            'hs_issue_backdate_days' => ['group' => 'ppe', 'label' => 'PPE issues and closes may be dated back', 'help' => 'An officer who records on Monday what was handed out on Friday. 0 means today only. Never the future. "Already held" rows may be dated any past day.', 'type' => 'int', 'min' => 0, 'max' => 60, 'unit' => 'days'],

            'hs_export_max_rows' => ['group' => 'exports', 'label' => 'Most rows in one export', 'help' => 'Over this the user is asked to narrow the filters; nothing is silently cut off.', 'type' => 'int', 'min' => 100, 'max' => 50000, 'unit' => 'rows'],

            'hs_require_second_approver' => ['group' => 'workflow', 'label' => 'Second approver for High and Critical closures', 'help' => 'When on, whoever sent an incident for approval cannot also approve it (super_admin included).', 'type' => 'bool', 'min' => null, 'max' => null, 'unit' => ''],
            'hs_emergency_contacts' => ['group' => 'workflow', 'label' => 'Emergency contacts on the report form', 'help' => 'Shown above the report form. Entries separated by "|", each "Label: number", for example "Ambulance: 193 | Fire: 192". Empty hides the strip.', 'type' => 'text', 'min' => null, 'max' => 500, 'unit' => ''],
        ];
    }

    /** @var array<string, string>|null name => stored value, read once per instance */
    protected ?array $saved = null;

    /** The value in force. */
    public function get(string $key): mixed
    {
        $saved = $this->savedValues();

        return array_key_exists($key, $saved) ? $this->cast($key, $saved[$key]) : $this->default($key);
    }

    /** Shortcut for code that cannot take the service as a dependency (model scopes, Blade). */
    public static function value(string $key): mixed
    {
        return app(self::class)->get($key);
    }

    /** What config/gwl.php (and .env) say, ignoring anything saved. */
    public function default(string $key): mixed
    {
        return $this->cast($key, config('gwl.'.$key));
    }

    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->savedValues());
    }

    /** @return array<string, mixed> every setting's value in force */
    public function all(): array
    {
        $values = [];

        foreach (array_keys(self::definitions()) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /** A threshold list as the text a person types: "60, 30, 7, 0, -7, -30". @param  list<int>  $list */
    public static function listToText(array $list): string
    {
        return implode(', ', $list);
    }

    /**
     * Saves the form: validates every given value (and the rules between them), stores the ones that differ from their
     * default, removes the ones that now equal it, and audits each change with the old and new value.
     *
     * @param  array<string, mixed>  $values  key => value for the settings being saved
     * @return array<string, array{from: mixed, to: mixed}> what changed
     *
     * @throws ValidationException
     */
    public function save(array $values, ?int $actorId = null): array
    {
        $definitions = self::definitions();
        $values = array_intersect_key($values, $definitions);
        $clean = [];
        $errors = [];

        foreach ($values as $key => $raw) {
            try {
                $clean[$key] = $this->validated($key, $raw);
            } catch (ValidationException $exception) {
                $errors[$key] = $exception->errors()['value'][0] ?? 'Not valid.';
            }
        }

        $merged = fn (string $key) => array_key_exists($key, $clean) ? $clean[$key] : $this->get($key);

        if (! isset($errors['hs_expiry_critical_days']) && ! isset($errors['hs_expiry_warning_days']) && $merged('hs_expiry_critical_days') >= $merged('hs_expiry_warning_days')) {
            $errors['hs_expiry_critical_days'] = 'The critical window must be shorter than the warning window.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changes = DB::transaction(function () use ($clean, $actorId): array {
            $changes = [];

            foreach ($clean as $key => $new) {
                $before = $this->get($key);

                if ($this->same($new, $this->default($key))) {
                    HsSetting::query()->where('name', $key)->delete();
                } else {
                    HsSetting::query()->updateOrCreate(['name' => $key], ['value' => $this->store($key, $new), 'updated_by' => $actorId]);
                }

                if (! $this->same($new, $before)) {
                    $changes[$key] = ['from' => $before, 'to' => $new];
                }
            }

            return $changes;
        });

        $this->forget();

        foreach ($changes as $key => $change) {
            Audit::log('health_safety.setting_changed', Permission::MODULE_HEALTH_SAFETY, 'hs_settings', null, [
                'key' => $key,
                'old' => $change['from'],
                'new' => $change['to'],
            ]);
        }

        return $changes;
    }

    /**
     * Puts the given settings (or every one) back to their defaults.
     *
     * @param  list<string>|null  $keys
     * @return list<string> the settings that had been edited
     */
    public function reset(?array $keys = null): array
    {
        $names = $keys === null ? array_keys(self::definitions()) : array_values(array_intersect($keys, array_keys(self::definitions())));
        $edited = array_values(array_filter($names, fn (string $name) => $this->isOverridden($name)));

        if ($edited === []) {
            return [];
        }

        $before = [];

        foreach ($edited as $name) {
            $before[$name] = $this->get($name);
        }

        HsSetting::query()->whereIn('name', $edited)->delete();
        $this->forget();

        foreach ($edited as $name) {
            Audit::log('health_safety.setting_changed', Permission::MODULE_HEALTH_SAFETY, 'hs_settings', null, [
                'key' => $name,
                'old' => $before[$name],
                'new' => $this->default($name),
                'reset' => true,
            ]);
        }

        return $edited;
    }

    /** Forgets what was read, so the next get() reads the table again (after a save, and in tests). */
    public function forget(): void
    {
        $this->saved = null;
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string, string> */
    protected function savedValues(): array
    {
        if ($this->saved !== null) {
            return $this->saved;
        }

        try {
            return $this->saved = HsSetting::query()->pluck('value', 'name')->all();
        } catch (\Throwable) {
            // A fresh install, or while migrating: the table is not there yet, so everything is at its default. Not kept,
            // so the table is looked at again once it exists.
            return [];
        }
    }

    /** @throws ValidationException (under the key "value") */
    protected function validated(string $key, mixed $raw): mixed
    {
        $definition = self::definitions()[$key];
        $fail = fn (string $message) => throw ValidationException::withMessages(['value' => $message]);
        $label = $definition['label'];

        switch ($definition['type']) {
            case 'int':
                if (is_string($raw)) {
                    $raw = trim($raw);
                }

                if ($raw === '' || $raw === null || ! is_numeric($raw) || (float) $raw != (int) $raw) {
                    $fail($label.' must be a whole number.');
                }

                $number = (int) $raw;

                if ($number < $definition['min'] || $number > $definition['max']) {
                    $fail($label.' must be between '.$definition['min'].' and '.$definition['max'].' '.$definition['unit'].'.');
                }

                return $number;

            case 'bool':
                return filter_var($raw, FILTER_VALIDATE_BOOLEAN);

            case 'text':
                $text = trim((string) $raw);

                if (mb_strlen($text) > $definition['max']) {
                    $fail($label.' is too long (at most '.$definition['max'].' characters).');
                }

                return $text;

            default: // intlist
                $items = is_array($raw) ? $raw : preg_split('/[\s,;]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
                $list = [];

                foreach ($items as $item) {
                    $item = is_string($item) ? trim($item) : $item;

                    if (! is_numeric($item) || (float) $item != (int) $item) {
                        $fail($label.' must be whole numbers separated by commas.');
                    }

                    $list[] = (int) $item;
                }

                if ($list === []) {
                    $fail($label.': give at least one number.');
                }

                if (count($list) !== count(array_unique($list))) {
                    $fail($label.' must not repeat a number.');
                }

                foreach ($list as $number) {
                    if ($number < $definition['min'] || $number > $definition['max']) {
                        $fail($label.' must be between '.$definition['min'].' and '.$definition['max'].' days.');
                    }
                }

                rsort($list);

                return $list;
        }
    }

    protected function cast(string $key, mixed $value): mixed
    {
        return match (self::definitions()[$key]['type'] ?? 'text') {
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'intlist' => $this->castList($value),
            default => (string) ($value ?? ''),
        };
    }

    /** @return list<int> highest first */
    protected function castList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
        $list = array_values(array_unique(array_map('intval', array_filter((array) $items, fn ($item) => $item !== '' && $item !== null))));
        rsort($list);

        return $list;
    }

    protected function store(string $key, mixed $value): string
    {
        return match (self::definitions()[$key]['type']) {
            'bool' => $value ? '1' : '0',
            'intlist' => implode(',', $value),
            default => (string) $value,
        };
    }

    protected function same(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }
}
