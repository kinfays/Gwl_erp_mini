<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\FinSupplier;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY writer of the supplier list (`fin_suppliers`): the Suppliers screen, the PV form's "add a supplier" panel and the
 * Excel import all come through here. One list for the whole company, NOT location-scoped (a supplier is shared; what a
 * location may see of the vouchers paid to one is FinanceScope's).
 *
 *  - Adding or editing needs finance.manage_suppliers; switching one off or on needs finance.deactivate_suppliers (it affects every
 *    location, so it is granted to few). Both are checked here as well as in the screens and routes.
 *  - Only the name is required. TIN, phone and code are optional and a blank is stored as NULL, never ''. When one is given its
 *    format is checked (a wrong one is refused, a missing one is not).
 *  - A supplier already on the list is refused, NAMING the one that is there: the same name (trimmed, spaces collapsed, upper case:
 *    `name_key`, unique) or the same TIN (unique). A name that is merely LIKE an existing one is a warning the person must
 *    acknowledge (SupplierMatcher); the import lists these instead of stopping.
 *  - Nothing is deleted: a supplier is deactivated. A voucher keeps the supplier id AND the name as it was when saved, so renaming a
 *    supplier never rewrites an old voucher. Audit rows hold ids and the names of the fields changed, never a name, TIN or phone.
 */
class SupplierService
{
    public const MODULE = 'finance';

    /** Letters, digits and hyphens, 6 to 20 characters, upper case (a business TIN, or a Ghana Card number with its hyphens). */
    public const TIN_PATTERN = '/^[A-Z0-9][A-Z0-9\-]{5,19}$/';

    /** Optional "+", then 9 to 15 digits. */
    public const PHONE_PATTERN = '/^\+?\d{9,15}$/';

    public function __construct(protected FinanceScope $scope) {}

    // ------------------------------------------------------------------ writes

    /**
     * @param  array{name?: mixed, code?: mixed, tin?: mixed, phone?: mixed}  $data
     * @param  bool  $acknowledgeSimilar  the person has seen the look-alike suppliers and says this one is different
     * @param  bool  $audit  false only for the import, which writes ONE summary row for the whole file
     */
    public function create(User $actor, array $data, bool $acknowledgeSimilar = false, bool $audit = true): FinSupplier
    {
        $this->authorise($actor, 'finance.manage_suppliers');
        $clean = $this->clean($data);
        $own = $this->scope->ownLocation($actor);

        try {
            return DB::transaction(function () use ($actor, $clean, $acknowledgeSimilar, $audit, $own) {
                $this->assertNotOnTheList($clean, null);

                if (! $acknowledgeSimilar) {
                    $this->assertNotSimilar($clean['name'], null);
                }

                $supplier = FinSupplier::query()->create($clean + [
                    'is_active' => true,
                    'created_by' => $actor->id,
                    'first_region_id' => $own['region_id'] ?? null,
                    'first_district_id' => $own['district_id'] ?? null,
                ]);

                if ($audit) {
                    $this->audit('finance.supplier_created', $supplier->id, []);
                }

                return $supplier;
            });
        } catch (UniqueConstraintViolationException) {
            // Two people adding the same supplier at once: the unique indexes decide, and the loser is told so.
            throw ValidationException::withMessages(['name' => 'That supplier (or its TIN) was just added by someone else.']);
        }
    }

    /** @param  array{name?: mixed, code?: mixed, tin?: mixed, phone?: mixed}  $data */
    public function update(User $actor, int $supplierId, array $data, bool $acknowledgeSimilar = false): FinSupplier
    {
        $this->authorise($actor, 'finance.manage_suppliers');
        $clean = $this->clean($data);

        try {
            return DB::transaction(function () use ($supplierId, $clean, $acknowledgeSimilar) {
                $supplier = FinSupplier::query()->lockForUpdate()->findOrFail($supplierId);
                $this->assertNotOnTheList($clean, $supplier->id);

                // A look-alike matters when the NAME changes; leaving an old near-duplicate alone is not a new mistake.
                if (! $acknowledgeSimilar && $clean['name_key'] !== $supplier->name_key) {
                    $this->assertNotSimilar($clean['name'], $supplier->id);
                }

                $changed = collect(['name', 'code', 'tin', 'phone'])->filter(fn (string $field) => $supplier->{$field} !== $clean[$field])->values()->all();

                if ($changed === []) {
                    return $supplier;
                }

                $supplier->forceFill($clean)->save();
                $this->audit('finance.supplier_updated', $supplier->id, ['fields' => $changed]);

                return $supplier;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'That name or TIN belongs to another supplier.']);
        }
    }

    public function setActive(User $actor, int $supplierId, bool $active): FinSupplier
    {
        $this->authorise($actor, 'finance.deactivate_suppliers');

        return DB::transaction(function () use ($supplierId, $active) {
            $supplier = FinSupplier::query()->lockForUpdate()->findOrFail($supplierId);

            if ($supplier->is_active !== $active) {
                $supplier->forceFill(['is_active' => $active])->save();
                $this->audit($active ? 'finance.supplier_reactivated' : 'finance.supplier_deactivated', $supplier->id, []);
            }

            return $supplier;
        });
    }

    /** The import's one summary row for a whole file: counts only. */
    public function auditImport(array $counts): void
    {
        AuditLog::record('finance.suppliers_imported', self::MODULE, 'fin_suppliers', null, null, null, $counts);
    }

    // ------------------------------------------------------------------ reads used while typing and importing

    /**
     * Suppliers that look like the typed name (so the person can see them before adding another). Exact matches are included:
     * they are what an add would be refused for.
     *
     * @return list<array{id: int, name: string, active: bool}>
     */
    public function nearMatches(string $name, ?int $exceptId = null): array
    {
        $name = $this->tidy($name);

        if ($name === '') {
            return [];
        }

        $exact = FinSupplier::query()->where('name_key', $this->nameKey($name))
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->get(['id', 'name', 'is_active'])
            ->map(fn (FinSupplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name, 'active' => (bool) $supplier->is_active])
            ->all();

        $similar = $this->matcher()->matches($name, $exceptId);

        return collect([...$exact, ...$similar])->unique('id')->take(5)->values()->all();
    }

    /** An in-memory index of every supplier name, for a caller (the import) that tests many names. */
    public function matcher(): SupplierMatcher
    {
        return new SupplierMatcher(FinSupplier::query()->get(['id', 'name', 'is_active']));
    }

    public function findByName(string $name): ?FinSupplier
    {
        return FinSupplier::query()->where('name_key', $this->nameKey($this->tidy($name)))->first();
    }

    // ------------------------------------------------------------------ cleaning

    /**
     * Tidies and checks the typed values; every problem is reported at once.
     *
     * @return array{name: string, name_key: string, code: string|null, tin: string|null, phone: string|null}
     */
    public function clean(array $data): array
    {
        $errors = [];
        $name = $this->tidy((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 200) {
            $errors['name'] = 'Enter the supplier\'s name (up to 200 characters).';
        }

        $code = $this->tidy((string) ($data['code'] ?? ''));

        if (mb_strlen($code) > 40) {
            $errors['code'] = 'The code is at most 40 characters.';
        }

        $tin = mb_strtoupper(preg_replace('/\s+/u', '', (string) ($data['tin'] ?? '')));

        if ($tin !== '' && ! preg_match(self::TIN_PATTERN, $tin)) {
            $errors['tin'] = 'A TIN has letters, digits and hyphens only, 6 to 20 characters. Leave it empty if you do not have it.';
        }

        $phone = $this->phone((string) ($data['phone'] ?? ''));

        if ($phone !== '' && ! preg_match(self::PHONE_PATTERN, $phone)) {
            $errors['phone'] = 'A phone number has 9 to 15 digits, with an optional + at the start. Leave it empty if you do not have it.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $name,
            'name_key' => $this->nameKey($name),
            'code' => $code === '' ? null : $code,
            'tin' => $tin === '' ? null : $tin,
            'phone' => $phone === '' ? null : $phone,
        ];
    }

    /** Spaces, dashes, dots and brackets removed; a 9-digit number that lost its leading zero in Excel gets it back. */
    protected function phone(string $value): string
    {
        $phone = preg_replace('/[\s\-.()]+/u', '', trim($value));

        if (preg_match('/^[1-9]\d{8}$/', $phone)) {
            return '0'.$phone;
        }

        return $phone;
    }

    protected function tidy(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    public function nameKey(string $name): string
    {
        return mb_strtoupper($this->tidy($name));
    }

    // ------------------------------------------------------------------ rules

    /** Refused, naming the supplier that is already there. */
    protected function assertNotOnTheList(array $clean, ?int $exceptId): void
    {
        $sameName = FinSupplier::query()->where('name_key', $clean['name_key'])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))->first();

        if ($sameName) {
            throw ValidationException::withMessages(['name' => '"'.$sameName->name.'" is already on the supplier list'.($sameName->is_active ? '.' : ' (switched off; ask for it to be switched on again).')]);
        }

        if ($clean['tin'] !== null) {
            $sameTin = FinSupplier::query()->where('tin', $clean['tin'])
                ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))->first();

            if ($sameTin) {
                throw ValidationException::withMessages(['tin' => 'This TIN already belongs to "'.$sameTin->name.'".']);
            }
        }
    }

    /** A look-alike must be acknowledged: the message lists them so the person can compare. */
    protected function assertNotSimilar(string $name, ?int $exceptId): void
    {
        $similar = $this->matcher()->matches($name, $exceptId);

        if ($similar !== []) {
            throw ValidationException::withMessages(['name' => 'Looks like a supplier that is already listed: '.collect($similar)->pluck('name')->implode('; ').'. If this one is different, tick "This is a different supplier" and save again.']);
        }
    }

    protected function authorise(User $actor, string $permission): void
    {
        abort_unless($this->scope->can($actor, $permission), 403, 'You do not have permission to change suppliers.');
    }

    /** Ids and the names of fields only: never a supplier's name, TIN or phone. */
    protected function audit(string $action, int $id, array $metadata): void
    {
        AuditLog::record($action, self::MODULE, 'fin_suppliers', $id, null, null, $metadata);
    }
}
