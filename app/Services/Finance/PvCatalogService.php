<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\FinPaymentVoucher;
use App\Models\FinPvCategory;
use App\Models\FinPvType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY writer of the payment voucher category / type catalogue (fin_pv_categories, fin_pv_types). The PV form's "add"
 * panel, Settings and the seed all go through here; never write those tables elsewhere. The catalogue is shared company-wide
 * (NOT location-scoped), needs finance.manage_categories (checked here, in the screens and in the routes), writes one audit
 * row per change (ids and flags only), and nothing is ever deleted: a category or type is deactivated. A deactivated one
 * cannot be picked for a new voucher but stays on old ones, and the voucher form keeps a voucher's current one selectable.
 *
 * A type used by a voucher cannot be moved to another category: the voucher stores both, and moving would make old vouchers
 * disagree with the catalogue.
 */
class PvCatalogService
{
    public const MODULE = 'finance';

    public function __construct(protected FinanceScope $scope) {}

    public function createCategory(User $actor, string $name): FinPvCategory
    {
        $this->authorise($actor);
        $name = $this->name($name);

        return DB::transaction(function () use ($actor, $name) {
            $this->assertCategoryNameFree($name);

            $category = FinPvCategory::query()->create(['name' => $name, 'sort_order' => (int) FinPvCategory::query()->max('sort_order') + 1, 'is_active' => true]);
            $this->audit('finance.category_created', 'fin_pv_categories', $category->id, []);

            return $category;
        });
    }

    public function renameCategory(User $actor, int $categoryId, string $name): FinPvCategory
    {
        $this->authorise($actor);
        $name = $this->name($name);

        return DB::transaction(function () use ($categoryId, $name) {
            $category = FinPvCategory::query()->lockForUpdate()->findOrFail($categoryId);
            $this->assertCategoryNameFree($name, $category->id);

            $category->forceFill(['name' => $name])->save();
            $this->audit('finance.category_renamed', 'fin_pv_categories', $category->id, []);

            return $category;
        });
    }

    public function setCategoryActive(User $actor, int $categoryId, bool $active): FinPvCategory
    {
        $this->authorise($actor);

        return DB::transaction(function () use ($categoryId, $active) {
            $category = FinPvCategory::query()->lockForUpdate()->findOrFail($categoryId);
            $category->forceFill(['is_active' => $active])->save();
            $this->audit($active ? 'finance.category_activated' : 'finance.category_deactivated', 'fin_pv_categories', $category->id, []);

            return $category;
        });
    }

    public function createType(User $actor, int $categoryId, string $name): FinPvType
    {
        $this->authorise($actor);
        $name = $this->name($name);

        return DB::transaction(function () use ($categoryId, $name) {
            $category = FinPvCategory::query()->lockForUpdate()->find($categoryId)
                ?? throw ValidationException::withMessages(['category_id' => 'Choose the category this type belongs to.']);
            $this->assertTypeNameFree($category->id, $name);

            $type = FinPvType::query()->create(['category_id' => $category->id, 'name' => $name, 'sort_order' => (int) FinPvType::query()->where('category_id', $category->id)->max('sort_order') + 1, 'is_active' => true]);
            $this->audit('finance.type_created', 'fin_pv_types', $type->id, ['category_id' => $category->id]);

            return $type;
        });
    }

    public function renameType(User $actor, int $typeId, string $name): FinPvType
    {
        $this->authorise($actor);
        $name = $this->name($name);

        return DB::transaction(function () use ($typeId, $name) {
            $type = FinPvType::query()->lockForUpdate()->findOrFail($typeId);
            $this->assertTypeNameFree($type->category_id, $name, $type->id);

            $type->forceFill(['name' => $name])->save();
            $this->audit('finance.type_renamed', 'fin_pv_types', $type->id, ['category_id' => $type->category_id]);

            return $type;
        });
    }

    public function moveType(User $actor, int $typeId, int $categoryId): FinPvType
    {
        $this->authorise($actor);

        return DB::transaction(function () use ($typeId, $categoryId) {
            $type = FinPvType::query()->lockForUpdate()->findOrFail($typeId);
            $category = FinPvCategory::query()->lockForUpdate()->find($categoryId)
                ?? throw ValidationException::withMessages(['category_id' => 'Choose the category to move it to.']);

            if ($type->category_id === $category->id) {
                return $type;
            }

            if (FinPaymentVoucher::query()->where('type_id', $type->id)->exists()) {
                throw ValidationException::withMessages(['category_id' => 'Vouchers already use this type, so it cannot move to another category. Add a new type there and deactivate this one.']);
            }

            $this->assertTypeNameFree($category->id, $type->name, $type->id);

            $from = $type->category_id;
            $type->forceFill(['category_id' => $category->id])->save();
            $this->audit('finance.type_moved', 'fin_pv_types', $type->id, ['from_category_id' => $from, 'to_category_id' => $category->id]);

            return $type;
        });
    }

    public function setTypeActive(User $actor, int $typeId, bool $active): FinPvType
    {
        $this->authorise($actor);

        return DB::transaction(function () use ($typeId, $active) {
            $type = FinPvType::query()->lockForUpdate()->findOrFail($typeId);
            $type->forceFill(['is_active' => $active])->save();
            $this->audit($active ? 'finance.type_activated' : 'finance.type_deactivated', 'fin_pv_types', $type->id, ['category_id' => $type->category_id]);

            return $type;
        });
    }

    // ------------------------------------------------------------------ internals

    protected function authorise(User $actor): void
    {
        abort_unless($this->scope->can($actor, 'finance.manage_categories'), 403, 'You do not have permission to change the categories.');
    }

    protected function name(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Enter a name of up to 120 characters.']);
        }

        return $name;
    }

    protected function assertCategoryNameFree(string $name, ?int $exceptId = null): void
    {
        $taken = FinPvCategory::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'There is already a category with that name.']);
        }
    }

    protected function assertTypeNameFree(int $categoryId, string $name, ?int $exceptId = null): void
    {
        $taken = FinPvType::query()
            ->where('category_id', $categoryId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'That category already has a type with that name.']);
        }
    }

    /** Ids and flags only: a name is not put in an audit row. */
    protected function audit(string $action, string $table, int $id, array $metadata): void
    {
        AuditLog::record($action, self::MODULE, $table, $id, null, null, $metadata);
    }
}
