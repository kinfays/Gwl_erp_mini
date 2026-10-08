<?php

namespace App\Services\HealthSafety;

use App\Models\HsPpeEntitlement;
use App\Models\HsPpeReorderLevel;
use App\Models\HsPpeType;
use App\Models\HsSite;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The PPE master data: types, what each job title is entitled to, and the reorder level of each store's items. Types,
 * entitlements and the store flag are master data (health_safety.manage_master_data); reorder levels too, for the stores in
 * the actor's part of the register. Nothing is deleted: a type is deactivated, and an entitlement or level set to nothing
 * simply has no row.
 */
class PpeSetupService
{
    public function __construct(protected EquipmentScope $scope) {}

    // ------------------------------------------------------------------ types

    /**
     * Create or change a type. The sizes (and whether there are any) are fixed once the type has stock or issues, because
     * those rows are keyed by size; a size already in use cannot be removed from the list.
     *
     * @param  array<string, mixed>  $data  name, category, has_sizes, sizes (list or comma-separated text), replacement_months, has_expiry, unit, is_active
     */
    public function saveType(User $actor, ?HsPpeType $type, array $data): HsPpeType
    {
        $this->guardMasterData($actor);

        $hasSizes = filter_var($data['has_sizes'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sizes = $hasSizes ? $this->sizes($data['sizes'] ?? []) : [];

        $validated = Validator::make([...$data, 'has_sizes' => $hasSizes], [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(array_keys(HsPpeType::CATEGORIES))],
            'replacement_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'unit' => ['nullable', 'string', 'max:30'],
        ])->validate();

        // Unique whatever the case ("hard hat" is "Hard hat"): the imports match a type by its name, so two that differ only in
        // case would be ambiguous.
        $taken = HsPpeType::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($validated['name']))])
            ->when($type, fn ($query) => $query->whereKeyNot($type->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'There is already a PPE type with that name.']);
        }

        if ($hasSizes && $sizes === []) {
            throw ValidationException::withMessages(['sizes' => 'List the sizes it comes in (for example S, M, L or 38, 39, 40).']);
        }

        if ($type && $type->isInUse()) {
            $removed = array_diff($type->sizeList(), $sizes);

            if ($type->has_sizes !== $hasSizes) {
                throw ValidationException::withMessages(['has_sizes' => 'This type already has stock or issues, so it cannot be changed between sized and not sized.']);
            }

            if ($hasSizes && $removed !== [] && $this->sizesInUse($type, $removed)) {
                throw ValidationException::withMessages(['sizes' => 'A size that has stock or issues cannot be removed: '.implode(', ', $this->sizesInUse($type, $removed)).'.']);
            }
        }

        $attributes = [
            'name' => trim($validated['name']),
            'category' => $validated['category'],
            'has_sizes' => $hasSizes,
            'sizes' => $hasSizes ? $sizes : null,
            'replacement_months' => filled($validated['replacement_months'] ?? null) ? (int) $validated['replacement_months'] : null,
            'has_expiry' => filter_var($data['has_expiry'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'unit' => trim((string) ($validated['unit'] ?? '')) ?: 'each',
            'is_active' => array_key_exists('is_active', $data) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) : ($type?->is_active ?? true),
        ];

        $type = $type ? tap($type)->update($attributes) : HsPpeType::query()->create($attributes);

        Audit::log('health_safety.ppe_type_saved', 'health_safety', 'hs_ppe_types', $type->id, [
            'name' => $type->name,
            'active' => $type->is_active,
        ]);

        return $type;
    }

    /**
     * Save several types at once (the "Load suggested types" list), all or nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<HsPpeType>
     */
    public function saveTypes(User $actor, array $rows): array
    {
        $this->guardMasterData($actor);

        return DB::transaction(function () use ($actor, $rows) {
            $saved = [];

            foreach ($rows as $index => $row) {
                if (blank($row['name'] ?? null)) {
                    continue;
                }

                try {
                    $saved[] = $this->saveType($actor, null, $row);
                } catch (ValidationException $exception) {
                    // Say which row, so the officer can find it in a long list.
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ['rows.'.$index.'.'.$field => $messages])->all());
                }
            }

            return $saved;
        });
    }

    // ------------------------------------------------------------------ entitlements

    /**
     * Set what a job title is entitled to: a quantity of at least 1 per type, or nothing for no entitlement. Replaces the
     * title's rows for the active types listed; types not listed are left as they are.
     *
     * @param  array<int|string, int|string|null>  $quantities  ppe_type_id => quantity (blank or 0: no entitlement)
     */
    public function saveEntitlements(User $actor, JobTitle $title, array $quantities): int
    {
        $this->guardMasterData($actor);

        return DB::transaction(function () use ($actor, $title, $quantities) {
            $set = 0;

            foreach ($quantities as $typeId => $quantity) {
                $type = HsPpeType::query()->find($typeId);

                if (! $type) {
                    continue;
                }

                if ($quantity === null || $quantity === '' || (is_numeric($quantity) && (int) $quantity === 0)) {
                    HsPpeEntitlement::query()->where('job_title_id', $title->id)->where('ppe_type_id', $type->id)->delete();

                    continue;
                }

                if (! is_numeric($quantity) || (int) $quantity != $quantity || (int) $quantity < 1 || (int) $quantity > 1000) {
                    throw ValidationException::withMessages(['quantities.'.$typeId => 'Enter a whole number of at least 1, or leave it empty for no entitlement.']);
                }

                HsPpeEntitlement::query()->updateOrCreate(['job_title_id' => $title->id, 'ppe_type_id' => $type->id], ['quantity' => (int) $quantity]);
                $set++;
            }

            Audit::log('health_safety.ppe_entitlement_saved', 'health_safety', 'job_titles', $title->id, [
                'job_title' => $title->job_title_name,
                'entitled_to' => $set,
            ]);

            return $set;
        });
    }

    // ------------------------------------------------------------------ reorder levels

    /**
     * Set reorder levels for stores in the actor's part of the register. A level of nothing removes it (no flag).
     *
     * @param  list<array{site_id: int|string, ppe_type_id: int|string, level: int|string|null}>  $levels
     */
    public function saveReorderLevels(User $actor, array $levels): int
    {
        $this->guardMasterData($actor);

        return DB::transaction(function () use ($actor, $levels) {
            $changed = 0;

            foreach ($levels as $row) {
                $store = HsSite::query()->find($row['site_id'] ?? null);
                $type = HsPpeType::query()->find($row['ppe_type_id'] ?? null);

                if (! $store || ! $type) {
                    continue;
                }

                abort_unless($this->scope->contains($actor, $store), 403, 'That store is outside your region.');

                if (! $store->is_ppe_store) {
                    throw ValidationException::withMessages(['site_id' => $store->name.' is not a PPE store.']);
                }

                $level = $row['level'] ?? null;
                $existing = HsPpeReorderLevel::query()->where('site_id', $store->id)->where('ppe_type_id', $type->id)->first();

                if ($level === null || $level === '') {
                    if ($existing) {
                        $existing->delete();
                        $changed++;
                        $this->auditLevel($store, $type, null);
                    }

                    continue;
                }

                if (! is_numeric($level) || (int) $level != $level || (int) $level < 0 || (int) $level > 1000000) {
                    throw ValidationException::withMessages(['level' => 'A reorder level is a whole number, 0 or more, or empty for none.']);
                }

                if ($existing && (int) $existing->level === (int) $level) {
                    continue;
                }

                HsPpeReorderLevel::query()->updateOrCreate(['site_id' => $store->id, 'ppe_type_id' => $type->id], ['level' => (int) $level]);
                $changed++;
                $this->auditLevel($store, $type, (int) $level);
            }

            return $changed;
        });
    }

    // ------------------------------------------------------------------ internals

    /** @return list<string> distinct, trimmed, non-empty sizes from a list or comma-separated text */
    protected function sizes(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[,;\n]+/', (string) $value);

        return collect($items)
            ->map(fn ($size) => mb_substr(trim((string) $size), 0, 30))
            ->filter(fn (string $size) => $size !== '')
            ->unique()
            ->values()
            ->take(40)
            ->all();
    }

    /** @param  list<string>  $sizes
     * @return list<string> those of the sizes that stock or issues use */
    protected function sizesInUse(HsPpeType $type, array $sizes): array
    {
        return collect($sizes)->filter(fn (string $size) => $type->movements()->where('size', $size)->exists() || $type->issues()->where('size', $size)->exists())->values()->all();
    }

    protected function guardMasterData(User $actor): void
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_master_data'), 403, 'You may not change the PPE set-up.');
    }

    protected function auditLevel(HsSite $store, HsPpeType $type, ?int $level): void
    {
        Audit::log('health_safety.ppe_reorder_level_saved', 'health_safety', 'hs_ppe_reorder_levels', null, [
            'store' => $store->name,
            'type' => $type->name,
            'level' => $level,
        ]);
    }
}
