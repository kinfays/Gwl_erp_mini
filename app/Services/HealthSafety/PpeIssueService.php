<?php

namespace App\Services\HealthSafety;

use App\Models\Employee;
use App\Models\HsPpeIssue;
use App\Models\HsPpeStockMovement;
use App\Models\HsPpeType;
use App\Models\HsSite;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PPE issued to staff. An issue posts its line to the stock ledger in the same transaction (unless it is an "already held"
 * one, which only records who holds what), may close the rows it replaces in that same transaction, and once closed is
 * never changed. Only the employee an issue was made to can confirm receipt, and only once.
 *
 * Rules: the size is required exactly when the type has sizes and must be one of them; expires_on is required exactly when
 * the type has an expiry; the replacement date is the EARLIER of issued_on plus the type's replacement months and expires_on
 * (either may be missing, and then there is no date and the issue is never flagged). A normal issue is dated today or a few days back
 * (hs_issue_backdate_days): it takes stock out of a store. An "already held" (historic) issue may carry any past date.
 */
class PpeIssueService
{
    public function __construct(
        protected EquipmentScope $scope,
        protected PpeStockService $stock,
    ) {}

    /**
     * Issue PPE to an employee, optionally closing the rows it replaces.
     *
     * @param  array<string, mixed>  $data  employee_id, ppe_type_id, size, quantity, is_historic, issued_on (historic only), expires_on, store_id (not for historic)
     * @param  list<array{issue_id: int|string, outcome: string, note?: string|null, store_id?: int|string|null}>  $closings  the employee's existing open rows of this type that the new issue replaces
     */
    public function issue(User $actor, array $data, array $closings = [], bool $audit = true): HsPpeIssue
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_ppe'), 403, 'You may not issue PPE.');

        $employee = Employee::query()->find($data['employee_id'] ?? null);

        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => 'Choose the member of staff.']);
        }

        abort_unless($this->scope->contains($actor, $employee), 403, 'That member of staff is outside your region.');

        if (! $employee->is_active) {
            throw ValidationException::withMessages(['employee_id' => 'That member of staff is no longer active.']);
        }

        $historic = (bool) ($data['is_historic'] ?? false);
        $type = HsPpeType::query()->find($data['ppe_type_id'] ?? null);

        if (! $type || ! $type->is_active) {
            throw ValidationException::withMessages(['ppe_type_id' => 'Choose an active PPE type.']);
        }

        $size = $this->stock->normaliseSize($type, $data['size'] ?? null);
        $quantity = $this->quantity($data['quantity'] ?? null);
        $issuedOn = $this->issuedOn($data['issued_on'] ?? null, $historic);
        $expiresOn = $this->expiresOn($type, $data['expires_on'] ?? null, $issuedOn);
        $store = $historic ? null : $this->store($actor, $data['store_id'] ?? null);

        $issue = DB::transaction(function () use ($actor, $employee, $type, $size, $quantity, $issuedOn, $expiresOn, $historic, $store, $closings, $audit) {
            // Serialise this type's movements (the ledger post below takes the same lock).
            $type = HsPpeType::query()->lockForUpdate()->findOrFail($type->id);

            $issue = HsPpeIssue::query()->create([
                'employee_id' => $employee->id,
                'ppe_type_id' => $type->id,
                'size' => $size,
                'quantity' => $quantity,
                'issued_on' => $issuedOn,
                'issued_by' => $actor->id,
                'replace_due_on' => $this->replaceDueOn($type, $issuedOn, $expiresOn),
                'expires_on' => $expiresOn,
                'is_historic' => $historic,
                'status' => HsPpeIssue::STATUS_ISSUED,
            ]);

            if (! $historic) {
                $this->stock->post($actor, $store, $type, $size, HsPpeStockMovement::ISSUE, -$quantity, ['issue_id' => $issue->id, 'occurred_on' => $issuedOn]);
            }

            foreach ($closings as $closing) {
                $this->closeReplaced($actor, $issue, $employee, $type, $closing);
            }

            if ($audit) {
                Audit::log('health_safety.ppe_issued', 'health_safety', 'hs_ppe_issues', $issue->id, [
                    'employee' => $employee->staff_id,
                    'type' => $type->name,
                    'size' => $size,
                    'quantity' => $quantity,
                    'historic' => $historic,
                    'replaced' => count($closings),
                ]);
            }

            return $issue;
        });

        return $issue->load(['employee', 'type']);
    }

    /**
     * Close an open issue: returned (back into a store), worn out, damaged or lost (nothing goes back). An issue closes
     * once; a closed issue cannot be changed.
     */
    public function close(User $actor, HsPpeIssue $issue, string $outcome, ?string $closedOn = null, ?string $note = null, ?HsSite $store = null): HsPpeIssue
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_ppe'), 403, 'You may not close PPE issues.');

        return DB::transaction(function () use ($actor, $issue, $outcome, $closedOn, $note, $store) {
            $locked = HsPpeIssue::query()->lockForUpdate()->with(['employee', 'type'])->findOrFail($issue->id);

            abort_unless($this->scope->contains($actor, $locked->employee), 403, 'That member of staff is outside your region.');

            $this->closeRow($actor, $locked, $outcome, $this->closeDate($closedOn, $locked), $note, $store, null);

            return $locked->refresh();
        });
    }

    /** The employee an issue was made to confirms they received it. Once; nobody else can. */
    public function acknowledge(User $actor, HsPpeIssue $issue): HsPpeIssue
    {
        return DB::transaction(function () use ($actor, $issue) {
            $locked = HsPpeIssue::query()->lockForUpdate()->with('type')->findOrFail($issue->id);
            $employeeId = $this->scope->employeeOf($actor)?->id;

            abort_unless($employeeId !== null && (int) $employeeId === (int) $locked->employee_id, 403, 'This PPE was not issued to you.');

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['status' => 'This issue is already closed.']);
            }

            if ($locked->acknowledged_at !== null) {
                throw ValidationException::withMessages(['status' => 'You have already confirmed receipt of this.']);
            }

            $locked->forceFill(['acknowledged_at' => now()])->save();

            Audit::log('health_safety.ppe_acknowledged', 'health_safety', 'hs_ppe_issues', $locked->id, [
                'type' => $locked->type->name,
                'quantity' => $locked->quantity,
            ]);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ closing

    /**
     * One row of the replacement step: it must be an open row of the same employee and type, and is closed with the new
     * issue's date and linked to it.
     *
     * @param  array{issue_id: int|string, outcome: string, note?: string|null, store_id?: int|string|null}  $closing
     */
    protected function closeReplaced(User $actor, HsPpeIssue $new, Employee $employee, HsPpeType $type, array $closing): void
    {
        $old = HsPpeIssue::query()->lockForUpdate()->with('type')->find($closing['issue_id'] ?? null);

        if (! $old || (int) $old->employee_id !== (int) $employee->id || (int) $old->ppe_type_id !== (int) $type->id) {
            throw ValidationException::withMessages(['closings' => 'A row being replaced must be this person\'s own PPE of the same type.']);
        }

        $store = filled($closing['store_id'] ?? null) ? HsSite::query()->find($closing['store_id']) : null;
        $closedOn = $new->issued_on->lt($old->issued_on) ? $old->issued_on : $new->issued_on;

        $this->closeRow($actor, $old, (string) ($closing['outcome'] ?? ''), $closedOn, $closing['note'] ?? null, $store, $new);
    }

    /** The one place an issue is closed, whether on its own or as part of a replacement. */
    protected function closeRow(User $actor, HsPpeIssue $issue, string $outcome, Carbon $closedOn, ?string $note, ?HsSite $store, ?HsPpeIssue $replacedBy): void
    {
        if (! array_key_exists($outcome, HsPpeIssue::OUTCOMES)) {
            throw ValidationException::withMessages(['outcome' => 'Choose how it ended: returned, worn out, damaged or lost.']);
        }

        if (! $issue->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This issue is already closed.']);
        }

        // Only a returned item that came out of a store goes back into one; a historic row never had stock taken.
        if ($outcome === HsPpeIssue::STATUS_RETURNED && ! $issue->is_historic) {
            if (! $store) {
                throw ValidationException::withMessages(['store_id' => 'Choose the store the PPE was returned to.']);
            }

            $this->stock->post($actor, $store, $issue->type, $issue->size, HsPpeStockMovement::RETURN, $issue->quantity, ['issue_id' => $issue->id, 'occurred_on' => $closedOn]);
        }

        $issue->forceFill([
            'status' => $outcome,
            'closed_on' => $closedOn,
            'close_note' => filled($note) ? mb_substr(trim($note), 0, 1000) : null,
            'replaced_by_issue_id' => $replacedBy?->id,
        ])->save();

        Audit::log('health_safety.ppe_issue_closed', 'health_safety', 'hs_ppe_issues', $issue->id, [
            'outcome' => $outcome,
            'type' => $issue->type->name,
            'quantity' => $issue->quantity,
            'replaced' => $replacedBy !== null,
        ]);
    }

    // ------------------------------------------------------------------ inputs

    protected function quantity(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > 10000) {
            throw ValidationException::withMessages(['quantity' => 'Enter a whole number of at least 1.']);
        }

        return (int) $value;
    }

    /**
     * A normal issue may be dated today or up to hs_issue_backdate_days earlier (an officer records on Monday what was handed
     * out on Friday); never the future. An already-held one may be dated any day in the past (or today).
     */
    protected function issuedOn(mixed $value, bool $historic): Carbon
    {
        if (! $historic) {
            $date = $this->date($value, 'issued_on') ?? today();

            if ($date->gt(today())) {
                throw ValidationException::withMessages(['issued_on' => 'The date cannot be in the future.']);
            }

            $this->guardBackdate($date, 'issued_on', 'PPE taken from a store');

            return $date;
        }

        $date = $this->date($value, 'issued_on');

        if ($date === null) {
            throw ValidationException::withMessages(['issued_on' => 'Enter the date it was given out.']);
        }

        if ($date->gt(today())) {
            throw ValidationException::withMessages(['issued_on' => 'The date cannot be in the future.']);
        }

        return $date;
    }

    /** @throws ValidationException when the date is further back than the configured window */
    protected function guardBackdate(Carbon $date, string $field, string $what): void
    {
        $days = (int) HealthSafetySettings::value('hs_issue_backdate_days');

        if ($date->lt(today()->subDays($days))) {
            throw ValidationException::withMessages([$field => $days === 0
                ? $what.' is recorded today: it cannot be back-dated. Use "already held" for something given out earlier.'
                : $what.' can be dated at most '.$days.' day'.($days === 1 ? '' : 's').' back. Use "already held" for something given out earlier.']);
        }
    }

    protected function expiresOn(HsPpeType $type, mixed $value, Carbon $issuedOn): ?Carbon
    {
        $date = $this->date($value, 'expires_on');

        if (! $type->has_expiry) {
            return null;
        }

        if ($date === null) {
            throw ValidationException::withMessages(['expires_on' => $type->name.' has an expiry date: enter it.']);
        }

        if ($date->lt($issuedOn)) {
            throw ValidationException::withMessages(['expires_on' => 'The expiry date cannot be before it was issued.']);
        }

        return $date;
    }

    /** The EARLIER of the type's service life from the issue date and the item's own expiry; null if neither applies. */
    protected function replaceDueOn(HsPpeType $type, Carbon $issuedOn, ?Carbon $expiresOn): ?Carbon
    {
        $byLife = $type->replacement_months ? $issuedOn->copy()->addMonths((int) $type->replacement_months) : null;

        return collect([$byLife, $expiresOn])->filter()->sort()->first();
    }

    protected function store(User $actor, mixed $storeId): HsSite
    {
        $store = HsSite::query()->find($storeId);

        if (! $store) {
            throw ValidationException::withMessages(['store_id' => 'Choose the store it comes from.']);
        }

        $this->stock->guardStore($actor, $store);

        return $store;
    }

    protected function closeDate(?string $closedOn, HsPpeIssue $issue): Carbon
    {
        $date = $this->date($closedOn, 'closed_on') ?? today();

        if ($date->gt(today())) {
            throw ValidationException::withMessages(['closed_on' => 'The date cannot be in the future.']);
        }

        if ($date->lt($issue->issued_on)) {
            throw ValidationException::withMessages(['closed_on' => 'It cannot be closed before it was issued.']);
        }

        // An item that came out of a store is closed within the same back-dating window as an issue; a row that was already
        // held (never in a store) may be closed on any day after it was issued.
        if (! $issue->is_historic) {
            $this->guardBackdate($date, 'closed_on', 'A close');
        }

        return $date;
    }

    protected function date(mixed $value, string $field): ?Carbon
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
}
