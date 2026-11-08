<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\FinCatalogOverride;
use App\Models\FinPaymentVoucher;
use App\Models\FinPvCategory;
use App\Models\FinPvType;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY reader of the category / type catalogue's NAMES and ACTIVE STATE, and its ONLY writer (fin_pv_categories,
 * fin_pv_types, fin_catalog_overrides). The voucher form, lists, Reports, exports and Settings all call effective(); no screen or
 * query works out a name or an on/off state itself.
 *
 * The catalogue is PER LOCATION, with "own row else system default" (the G-Water price pattern):
 *  - the seeded Staff Claims / Operations Activities rows are SYSTEM DEFAULTS (`unit` NULL). Only super_admin edits them.
 *  - a finance_manager (finance.manage_categories), for THEIR OWN location only: adds a location-owned category or type, renames
 *    ANY category / type for the location only (an override of the display name; other locations are unchanged), switches any
 *    off or on for the location only (an override flag; switching a category off switches its types off there), and moves the
 *    location's own types between the location's categories. A default row is never moved by a manager.
 *  - overrides live in fin_catalog_overrides and exist only while they differ from the default.
 *  - a name is unique (case-insensitive) within the location's effective list, and types within their category.
 *  - nothing is deleted. Every change writes an audit row holding ids, never a name.
 *  - a voucher keeps its category and type ids; its labels show the location's CURRENT effective name; a switched-off item stays
 *    visible on the vouchers already using it and on their edit form.
 *
 * A change bumps the books version of the location it touches (or of every location, for a default), so cached Reports and
 * exports never show a name from before it.
 */
class PvCatalogService
{
    public const MODULE = 'finance';

    public function __construct(protected FinanceScope $scope) {}

    // ------------------------------------------------------------------ reading: the ONE effective list

    /**
     * The categories (each with its `types` relation) a location sees, with `effective_name`, `effective_active`, `is_default`,
     * `is_renamed` (a location override of the name) and `is_switched` (a location override of the flag) set on every item.
     * A type is effectively active only while its category is. $location null = the system defaults themselves.
     *
     * @param  array{region_id: int|null, district_id: int|null}|null  $location
     * @return EloquentCollection<int, FinPvCategory>
     */
    public function effective(?array $location): EloquentCollection
    {
        $unit = $location ? FinanceBooksVersion::unitKey($location) : null;
        $visible = fn ($query) => $query->where(fn ($inner) => $inner->whereNull('unit')->when($unit, fn ($owned) => $owned->orWhere('unit', $unit)));

        $categories = $visible(FinPvCategory::query())->orderBy('sort_order')->orderBy('id')->get();
        $types = $visible(FinPvType::query())->whereIn('category_id', $categories->modelKeys())->orderBy('sort_order')->orderBy('id')->get()->groupBy('category_id');
        $overrides = $unit
            ? FinCatalogOverride::query()->where('unit', $unit)->get()->groupBy('kind')->map(fn ($rows) => $rows->keyBy('item_id'))
            : collect();

        foreach ($categories as $category) {
            $this->decorate($category, $overrides->get(FinCatalogOverride::CATEGORY)?->get($category->id), true);

            $category->setRelation('types', ($types->get($category->id) ?? new EloquentCollection)
                ->each(fn (FinPvType $type) => $this->decorate($type, $overrides->get(FinCatalogOverride::TYPE)?->get($type->id), $category->effective_active))
                ->sortBy(fn (FinPvType $type) => [$type->sort_order, mb_strtolower($type->effective_name)])->values()
                ->pipe(fn (Collection $sorted) => new EloquentCollection($sorted->all())));
        }

        return $categories->sortBy(fn (FinPvCategory $category) => [$category->sort_order, mb_strtolower($category->effective_name)])->values()
            ->pipe(fn (Collection $sorted) => new EloquentCollection($sorted->all()));
    }

    /**
     * What the voucher form offers a location: its active categories and the active types of the chosen one. A voucher being
     * edited keeps its own category and type selectable even if they have since been switched off.
     *
     * @param  array{region_id: int|null, district_id: int|null}|null  $location
     * @return array{categories: Collection<int, FinPvCategory>, types: Collection<int, FinPvType>}
     */
    public function choices(?array $location, int $categoryId = 0, ?FinPaymentVoucher $existing = null): array
    {
        $all = $this->effective($location);
        $categories = $all->filter(fn (FinPvCategory $category) => $category->effective_active || $existing?->category_id === $category->id)->values();
        $chosen = $all->firstWhere('id', $categoryId);
        $types = $chosen
            ? $chosen->types->filter(fn (FinPvType $type) => $type->effective_active || $existing?->type_id === $type->id)->values()
            : collect();

        return ['categories' => $categories, 'types' => $types];
    }

    /**
     * Checks a voucher's category and type against the location's effective list (the workflow service calls this). A switched-off
     * item is refused for a new choice, but the voucher being edited keeps the one it already has.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return array{0: FinPvCategory, 1: FinPvType}
     */
    public function resolve(array $location, int $categoryId, int $typeId, ?FinPaymentVoucher $existing): array
    {
        $all = $this->effective($location);
        $category = $all->firstWhere('id', $categoryId);
        $type = $category?->types->firstWhere('id', $typeId);

        if (! $category && $existing && $existing->category_id === $categoryId) {
            $category = FinPvCategory::query()->find($categoryId); // kept on an existing voucher whatever happened since
            $category?->setRelation('types', FinPvType::query()->where('category_id', $categoryId)->get());
            $type = $category?->types->firstWhere('id', $typeId);
        }

        if (! $category) {
            throw ValidationException::withMessages(['category_id' => 'Choose a category.']);
        }

        if (! $type) {
            throw ValidationException::withMessages(['type_id' => 'Choose a type that belongs to the category.']);
        }

        if ((! $category->effective_active && $existing?->category_id !== $category->id) || (! $type->effective_active && $existing?->type_id !== $type->id)) {
            throw ValidationException::withMessages(['type_id' => 'That category or type is no longer in use. Choose another.']);
        }

        return [$category, $type];
    }

    /** The category and type labels of one voucher, at ITS location, read fresh. @return array{category: string, type: string} */
    public function labelsOf(FinPaymentVoucher $pv): array
    {
        $labels = $this->labelsFor([$pv]);

        return $labels[$pv->id];
    }

    /** Sets `category_label` / `type_label` on each voucher (two queries per location), so lists never do one per row. */
    public function labelPvs(iterable $pvs): void
    {
        $pvs = collect($pvs)->values();

        foreach ($this->labelsFor($pvs->all()) as $id => $labels) {
            $pv = $pvs->firstWhere('id', $id);
            $pv?->setAttribute('category_label', $labels['category']);
            $pv?->setAttribute('type_label', $labels['type']);
        }
    }

    /**
     * Label of an item for a view that sets locations side by side (the super_admin comparison): a default item by its default
     * name, so locations group together; a location-owned one as "<Location>: <name>".
     */
    public function comparisonLabel(string $kind, int $id): string
    {
        $item = $kind === FinCatalogOverride::TYPE ? FinPvType::query()->find($id) : FinPvCategory::query()->find($id);

        if (! $item) {
            return 'Unknown';
        }

        return $item->unit === null ? $item->name : $this->unitLabel($item->unit).': '.$item->name;
    }

    // ------------------------------------------------------------------ writing: categories

    /**
     * @param  int|null  $districtId  super_admin only: the district whose location the change is for
     * @param  bool  $defaults  super_admin only: change the SYSTEM DEFAULTS themselves
     */
    public function createCategory(User $actor, string $name, ?int $districtId = null, bool $defaults = false): FinPvCategory
    {
        $this->authorise($actor);
        $name = $this->name($name);
        $target = $this->target($actor, $districtId, $defaults);

        return DB::transaction(function () use ($actor, $name, $target) {
            $this->assertCategoryNameFree($target['location'], $name);

            $category = FinPvCategory::query()->create([
                'name' => $name,
                'unit' => $target['unit'],
                'sort_order' => (int) $this->effective($target['location'])->max('sort_order') + 1,
                'is_active' => true,
            ]);

            $this->audit('finance.category_created', 'fin_pv_categories', $category->id, $target);
            $this->changed($target);

            return $category;
        });
    }

    public function renameCategory(User $actor, int $categoryId, string $name, ?int $districtId = null, bool $defaults = false): FinPvCategory
    {
        $this->authorise($actor);
        $name = $this->name($name);
        $target = $this->target($actor, $districtId, $defaults);

        return DB::transaction(function () use ($actor, $categoryId, $name, $target) {
            $category = $this->lockedCategory($target, $categoryId);
            $this->assertCategoryNameFree($target['location'], $name, $category->id);

            $override = $category->unit === null && $target['unit'] !== null;
            $override
                ? $this->setOverride($actor, $target['unit'], FinCatalogOverride::CATEGORY, $category->id, ['display_name' => $name === $category->name ? null : $name])
                : $category->forceFill(['name' => $name])->save();

            $this->audit('finance.category_renamed', 'fin_pv_categories', $category->id, $target, ['override' => $override]);
            $this->changed($target);

            return $category->refresh();
        });
    }

    public function setCategoryActive(User $actor, int $categoryId, bool $active, ?int $districtId = null, bool $defaults = false): FinPvCategory
    {
        $this->authorise($actor);
        $target = $this->target($actor, $districtId, $defaults);

        return DB::transaction(function () use ($actor, $categoryId, $active, $target) {
            $category = $this->lockedCategory($target, $categoryId);

            $override = $category->unit === null && $target['unit'] !== null;
            $override
                ? $this->setOverride($actor, $target['unit'], FinCatalogOverride::CATEGORY, $category->id, ['is_active' => $active === $category->is_active ? null : $active])
                : $category->forceFill(['is_active' => $active])->save();

            $this->audit($active ? 'finance.category_activated' : 'finance.category_deactivated', 'fin_pv_categories', $category->id, $target, ['override' => $override]);
            $this->changed($target);

            return $category->refresh();
        });
    }

    // ------------------------------------------------------------------ writing: types

    public function createType(User $actor, int $categoryId, string $name, ?int $districtId = null, bool $defaults = false): FinPvType
    {
        $this->authorise($actor);
        $name = $this->name($name);
        $target = $this->target($actor, $districtId, $defaults);

        return DB::transaction(function () use ($categoryId, $name, $target) {
            $category = $this->effective($target['location'])->firstWhere('id', $categoryId)
                ?? throw ValidationException::withMessages(['category_id' => 'Choose the category this type belongs to.']);
            $this->assertTypeNameFree($target['location'], $category->id, $name);

            $type = FinPvType::query()->create([
                'category_id' => $category->id,
                'name' => $name,
                'unit' => $target['unit'],
                'sort_order' => (int) $category->types->max('sort_order') + 1,
                'is_active' => true,
            ]);

            $this->audit('finance.type_created', 'fin_pv_types', $type->id, $target, ['category_id' => $category->id]);
            $this->changed($target);

            return $type;
        });
    }

    public function renameType(User $actor, int $typeId, string $name, ?int $districtId = null, bool $defaults = false): FinPvType
    {
        $this->authorise($actor);
        $name = $this->name($name);
        $target = $this->target($actor, $districtId, $defaults);

        return DB::transaction(function () use ($actor, $typeId, $name, $target) {
            $type = $this->lockedType($target, $typeId);
            $this->assertTypeNameFree($target['location'], $type->category_id, $name, $type->id);

            $override = $type->unit === null && $target['unit'] !== null;
            $override
                ? $this->setOverride($actor, $target['unit'], FinCatalogOverride::TYPE, $type->id, ['display_name' => $name === $type->name ? null : $name])
                : $type->forceFill(['name' => $name])->save();

            $this->audit('finance.type_renamed', 'fin_pv_types', $type->id, $target, ['category_id' => $type->category_id, 'override' => $override]);
            $this->changed($target);

            return $type->refresh();
        });
    }

    public function setTypeActive(User $actor, int $typeId, bool $active, ?int $districtId = null, bool $defaults = false): FinPvType
    {
        $this->authorise($actor);
        $target = $this->target($actor, $districtId, $defaults);

        return DB::transaction(function () use ($actor, $typeId, $active, $target) {
            $type = $this->lockedType($target, $typeId);

            $override = $type->unit === null && $target['unit'] !== null;
            $override
                ? $this->setOverride($actor, $target['unit'], FinCatalogOverride::TYPE, $type->id, ['is_active' => $active === $type->is_active ? null : $active])
                : $type->forceFill(['is_active' => $active])->save();

            $this->audit($active ? 'finance.type_activated' : 'finance.type_deactivated', 'fin_pv_types', $type->id, $target, ['category_id' => $type->category_id, 'override' => $override]);
            $this->changed($target);

            return $type->refresh();
        });
    }

    /**
     * Moves a type to another category. A location moves only ITS OWN types, between its effective categories; a default type is
     * moved by super_admin editing the defaults. A type vouchers already use cannot move (the voucher stores both ids).
     */
    public function moveType(User $actor, int $typeId, int $categoryId, ?int $districtId = null, bool $defaults = false): FinPvType
    {
        $this->authorise($actor);
        $target = $this->target($actor, $districtId, $defaults);

        return DB::transaction(function () use ($typeId, $categoryId, $target) {
            $type = $this->lockedType($target, $typeId);

            if ($type->unit === null && $target['unit'] !== null) {
                throw ValidationException::withMessages(['category_id' => 'A standard type cannot be moved by a location. Add a type of your own in the other category and switch this one off.']);
            }

            $destination = $this->effective($target['location'])->firstWhere('id', $categoryId)
                ?? throw ValidationException::withMessages(['category_id' => 'Choose the category to move it to.']);

            if ($type->category_id === $destination->id) {
                return $type;
            }

            if (FinPaymentVoucher::query()->where('type_id', $type->id)->exists()) {
                throw ValidationException::withMessages(['category_id' => 'Vouchers already use this type, so it cannot move to another category. Add a new type there and switch this one off.']);
            }

            $this->assertTypeNameFree($target['location'], $destination->id, $type->name, $type->id);

            $from = $type->category_id;
            $type->forceFill(['category_id' => $destination->id])->save();
            $this->audit('finance.type_moved', 'fin_pv_types', $type->id, $target, ['from_category_id' => $from, 'to_category_id' => $destination->id]);
            $this->changed($target);

            return $type;
        });
    }

    // ------------------------------------------------------------------ internals

    protected function authorise(User $actor): void
    {
        abort_unless($this->scope->can($actor, 'finance.manage_categories'), 403, 'You do not have permission to change the categories.');
    }

    /**
     * Where a change goes: the actor's own location (a manager), the location super_admin chose, or (super_admin only) the
     * system defaults.
     *
     * @return array{unit: string|null, location: array{region_id: int, district_id: int|null}|null}
     */
    protected function target(User $actor, ?int $districtId, bool $defaults): array
    {
        if ($defaults) {
            abort_unless($this->scope->seesAll($actor), 403, 'Only the system administrator changes the standard list.');

            return ['unit' => null, 'location' => null];
        }

        $location = $this->scope->recordingLocation($actor, $districtId);

        return ['unit' => FinanceBooksVersion::unitKey($location), 'location' => $location];
    }

    protected function lockedCategory(array $target, int $id): FinPvCategory
    {
        abort_unless($this->effective($target['location'])->contains('id', $id), 404);

        return FinPvCategory::query()->lockForUpdate()->findOrFail($id);
    }

    protected function lockedType(array $target, int $id): FinPvType
    {
        abort_unless($this->effective($target['location'])->contains(fn (FinPvCategory $category) => $category->types->contains('id', $id)), 404);

        return FinPvType::query()->lockForUpdate()->findOrFail($id);
    }

    /** Stores a location's override of a default item; a row with both values back at the default is removed. */
    protected function setOverride(User $actor, string $unit, string $kind, int $itemId, array $values): void
    {
        $row = FinCatalogOverride::query()->firstOrNew(['unit' => $unit, 'kind' => $kind, 'item_id' => $itemId]);
        $row->forceFill($values + ['updated_by' => $actor->id]);

        if ($row->display_name === null && $row->is_active === null) {
            $row->exists && $row->delete();

            return;
        }

        $row->save();
    }

    /** Fills the computed fields of one item. A type is only effectively active while its category is. */
    protected function decorate(FinPvCategory|FinPvType $item, ?FinCatalogOverride $override, bool $parentActive): void
    {
        $isDefault = $item->unit === null;
        $ownActive = $isDefault && $override && $override->is_active !== null ? $override->is_active : $item->is_active;

        $item->setAttribute('is_default', $isDefault);
        $item->setAttribute('effective_name', $isDefault && $override?->display_name !== null ? $override->display_name : $item->name);
        $item->setAttribute('effective_active', $ownActive && $parentActive);
        $item->setAttribute('own_active', $ownActive);
        $item->setAttribute('is_renamed', $isDefault && $override?->display_name !== null);
        $item->setAttribute('is_switched', $isDefault && $override && $override->is_active !== null);
    }

    protected function name(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter a name of up to 120 characters.']);
        }

        return $name;
    }

    protected function assertCategoryNameFree(?array $location, string $name, ?int $exceptId = null): void
    {
        $taken = $this->effective($location)->contains(fn (FinPvCategory $category) => $category->id !== $exceptId && mb_strtolower($category->effective_name) === mb_strtolower($name));

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'There is already a category with that name.']);
        }
    }

    protected function assertTypeNameFree(?array $location, int $categoryId, string $name, ?int $exceptId = null): void
    {
        $category = $this->effective($location)->firstWhere('id', $categoryId);
        $taken = $category && $category->types->contains(fn (FinPvType $type) => $type->id !== $exceptId && mb_strtolower($type->effective_name) === mb_strtolower($name));

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'That category already has a type with that name.']);
        }
    }

    /** Ids and flags only: a name is never put in an audit row. */
    protected function audit(string $action, string $table, int $id, array $target, array $metadata = []): void
    {
        AuditLog::record($action, self::MODULE, $table, $id, null, null, ['unit' => $target['unit']] + $metadata);
    }

    /** Drops the cached figures that show the old names: one location's, or every location's for a default. */
    protected function changed(array $target): void
    {
        DB::afterCommit(fn () => $target['location'] ? FinanceBooksVersion::touch($target['location']) : FinanceBooksVersion::touchAll());
    }

    protected function unitLabel(string $unit): string
    {
        return $unit === 'ho' ? 'Head Office' : (string) (Region::query()->find((int) substr($unit, 1))?->region_name ?? 'Region');
    }

    /**
     * @param  list<FinPaymentVoucher>  $pvs
     * @return array<int, array{category: string, type: string}>
     */
    protected function labelsFor(array $pvs): array
    {
        $pvs = collect($pvs);
        $categoryNames = FinPvCategory::query()->whereIn('id', $pvs->pluck('category_id')->unique())->pluck('name', 'id');
        $typeNames = FinPvType::query()->whereIn('id', $pvs->pluck('type_id')->unique())->pluck('name', 'id');
        $units = $pvs->mapWithKeys(fn (FinPaymentVoucher $pv) => [$pv->id => FinanceBooksVersion::unitKey(['region_id' => $pv->region_id, 'district_id' => $pv->district_id])]);
        $overrides = FinCatalogOverride::query()->whereIn('unit', $units->unique()->all())->whereNotNull('display_name')->get()
            ->mapWithKeys(fn (FinCatalogOverride $row) => [$row->unit.'|'.$row->kind.'|'.$row->item_id => $row->display_name]);

        return $pvs->mapWithKeys(fn (FinPaymentVoucher $pv) => [$pv->id => [
            'category' => (string) ($overrides[$units[$pv->id].'|category|'.$pv->category_id] ?? $categoryNames[$pv->category_id] ?? ''),
            'type' => (string) ($overrides[$units[$pv->id].'|type|'.$pv->type_id] ?? $typeNames[$pv->type_id] ?? ''),
        ]])->all();
    }
}
