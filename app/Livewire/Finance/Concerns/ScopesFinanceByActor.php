<?php

namespace App\Livewire\Finance\Concerns;

use App\Models\District;
use App\Models\FinPaymentVoucher;
use App\Models\User;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PaymentVoucherWorkflowService;
use App\Services\Finance\PvConflictException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The Livewire-side wrapper of FinanceScope, used by every Finance and Audit component. A separate trait from the Assets,
 * Commercial, Health & Safety, Blog and G-Water ones on purpose (CLAUDE.md: scoping traits are not shared), and it holds no
 * rule of its own: the rules live in FinanceScope, which the services call too (FinanceScopeParityTest pins the two).
 *
 * Everyone but super_admin works in their own location (taken from their employee record). super_admin has none and picks the
 * district whose vouchers they are working in ($locationId) for a write, and may read every location.
 */
trait ScopesFinanceByActor
{
    /** super_admin only: the district whose vouchers a NEW voucher goes into. Ignored for everyone else. */
    public $locationId = null;

    protected function enforceFinance(string $module): void
    {
        abort_unless(config('gwl.finance_audit_enabled'), 404);
        $this->enforceLivewireModule($module);
    }

    protected function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function scope(): FinanceScope
    {
        return app(FinanceScope::class);
    }

    protected function workflow(): PaymentVoucherWorkflowService
    {
        return app(PaymentVoucherWorkflowService::class);
    }

    protected function guard(string $permission): void
    {
        abort_unless(Auth::user() && $this->scope()->can($this->actor(), $permission), 403, 'You do not have permission to do that.');
    }

    protected function can(string $permission): bool
    {
        return $this->scope()->can($this->actor(), $permission);
    }

    protected function isSuperAdmin(): bool
    {
        return $this->scope()->seesAll($this->actor());
    }

    /** The vouchers the user reaches, as Finance (drafts included) or as Audit. */
    protected function pvs(string $as): Builder
    {
        return $this->scope()->pvs($this->actor(), $as);
    }

    /** One voucher by id inside the user's reach: 404 outside it. */
    protected function pvOrFail(int $id, string $as): FinPaymentVoucher
    {
        return $this->pvs($as)->findOrFail($id);
    }

    /** Where a NEW voucher goes: the user's own location, or the one super_admin chose. Null = none yet. */
    protected function workingLocation(): ?array
    {
        if (! $this->isSuperAdmin()) {
            return $this->scope()->ownLocation($this->actor());
        }

        $district = $this->locationId ? District::query()->find((int) $this->locationId) : null;

        return $district ? ['region_id' => (int) $district->region_id, 'district_id' => (int) $district->id] : null;
    }

    protected function recordingDistrict(): ?int
    {
        return $this->isSuperAdmin() && $this->locationId ? (int) $this->locationId : null;
    }

    /** @return Collection<int, District> for super_admin's picker */
    protected function locationChoices(): Collection
    {
        return $this->isSuperAdmin()
            ? District::query()->with('region')->orderBy('district_name')->get()
            : collect();
    }

    /** @return array<string, mixed> what every screen's location picker needs */
    protected function locationView(): array
    {
        return [
            'isSuperAdmin' => $this->isSuperAdmin(),
            'locationChoices' => $this->locationChoices(),
            'location' => $this->workingLocation(),
        ];
    }

    /**
     * Runs a service call, turning its validation errors (keyed by field) into errors on the form array property, which is
     * where the screens show them. A version conflict becomes a warning the screen shows, never a silent overwrite.
     */
    protected function run(callable $action, string $prefix = 'form'): mixed
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key) => ["{$prefix}.{$key}" => $messages])->all()
            );
        } catch (PvConflictException $exception) {
            $this->addError('conflict', $exception->getMessage());

            return null;
        }
    }
}
