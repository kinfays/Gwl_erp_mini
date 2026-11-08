<?php

namespace App\Services\Finance;

use App\Events\Finance\PaymentVoucherActioned;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Employee;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvAttachment;
use App\Models\FinPvCategory;
use App\Models\FinPvEvent;
use App\Models\FinPvType;
use App\Models\FinSupplier;
use App\Models\User;
use App\Services\GWater\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every payment voucher write goes through here (docs/20-module-finance-audit.md). The rules, in the order each method applies
 * them:
 *  1. the permission (finance.* / audit.*), and for a NEW voucher the location it goes into (FinanceScope::recordingLocation);
 *  2. inside one transaction the voucher is RE-READ with lockForUpdate and authorised on that copy (another location's voucher
 *     is a 404, and a draft is never found as Audit), never trusted from the caller;
 *  3. the rule itself: the state it must be in, the version the form opened (PvConflictException), separation of duties;
 *  4. an immutable fin_pv_events row and an AuditLog row (ids, statuses and counts only: never the payee, amount, a comment,
 *     a reason or a file name);
 *  5. after the commit: the books version is bumped and PaymentVoucherActioned is fired (notices).
 *
 *   Draft -> With Audit -> (Audit tick) Audit acknowledged -> (Finance tick) Completed
 *   With Audit -> (Audit X, reason) Queried -> (Finance corrects, resubmits) With Audit, round + 1 ...
 *   Audit may withdraw its tick (reason) only while Audit acknowledged. Void (reason) from Draft, Queried, With Audit.
 *
 * The user who submitted a voucher in ANY round can never tick, query or withdraw on it, even holding both roles;
 * super_admin is not exempt. Money is integer pesewas (Money is the only cedis <-> pesewas conversion).
 */
class PaymentVoucherWorkflowService
{
    public function __construct(
        protected FinanceScope $scope,
        protected PvAttachmentService $files,
        protected PvCatalogService $catalogue,
    ) {}

    // ------------------------------------------------------------------ Finance: prepare

    /**
     * A new voucher, saved as a Draft in the creator's location. super_admin must say which district's books ($districtId).
     *
     * @param  array<string, mixed>  $data  pv_date, pv_no, payee_kind, payee_employee_id | payee_district_id | supplier_id,
     *                                       category_id, type_id, details, amount (cedis), remarks
     * @param  array<int, UploadedFile>  $files
     */
    public function createDraft(User $actor, array $data, array $files = [], ?int $districtId = null): Pv
    {
        $this->authorise($actor, 'finance.create_pv');

        $location = $this->scope->recordingLocation($actor, $districtId);
        $types = $this->files->inspectAll($files);
        $stored = [];

        try {
            return DB::transaction(function () use ($actor, $data, $files, $types, $location, &$stored) {
                $clean = $this->clean($actor, $location, $data, null);

                try {
                    $pv = Pv::query()->create([
                        ...$location,
                        ...$clean,
                        'status' => Pv::DRAFT,
                        'round' => 1,
                        'lock_version' => 1,
                        'created_by' => $actor->id,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw $this->numberUsed();
                }

                foreach (array_values($files) as $index => $file) {
                    $stored[] = $this->files->store($pv, $file, $types[$index], $actor)->path;
                }

                $this->audit('finance.pv_created', $pv, 'finance', ['attachments' => count($files)]);
                $this->afterCommit($pv, null);

                return $pv;
            });
        } catch (\Throwable $exception) {
            array_map($this->files->deleteFile(...), $stored);

            throw $exception;
        }
    }

    /**
     * Edits a voucher while it is a Draft or Queried. $expectedVersion is the lock_version the form opened: a newer one means
     * someone else changed it (PvConflictException). lock_version rises on every change of the content.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $newFiles
     * @param  array<int, int>  $removeAttachmentIds
     */
    public function update(User $actor, Pv|int $pv, array $data, int $expectedVersion, array $newFiles = [], array $removeAttachmentIds = []): Pv
    {
        $this->authorise($actor, 'finance.create_pv');
        $stored = [];
        $removed = [];

        try {
            return DB::transaction(function () use ($actor, $pv, $data, $expectedVersion, $newFiles, $removeAttachmentIds, &$stored, &$removed) {
                $fresh = $this->lockedPv($actor, $pv, FinanceScope::AS_FINANCE);
                $this->assertEditable($fresh);
                $this->assertVersion($fresh, $expectedVersion);

                $location = ['region_id' => (int) $fresh->region_id, 'district_id' => $fresh->district_id !== null ? (int) $fresh->district_id : null];
                $clean = $this->clean($actor, $location, $data, $fresh);

                $toRemove = $fresh->attachments()->whereIn('id', array_map('intval', $removeAttachmentIds))->get();
                $types = $this->files->inspectAll($newFiles, $fresh->attachments()->count() - $toRemove->count());
                $before = $fresh->contentSnapshot();

                try {
                    $fresh->forceFill($clean)->save();
                } catch (UniqueConstraintViolationException) {
                    throw $this->numberUsed();
                }

                foreach ($toRemove as $attachment) {
                    $removed[] = $attachment->path;
                    $attachment->delete();
                }

                foreach (array_values($newFiles) as $index => $file) {
                    $stored[] = $this->files->store($fresh, $file, $types[$index], $actor)->path;
                }

                $fresh->unsetRelations();
                $changed = array_keys($this->diff($before, $fresh->contentSnapshot()));

                // Adding or removing a file is a change of content even when the list reads the same (a file swapped for another).
                if ($toRemove->isNotEmpty() || $newFiles !== []) {
                    $changed = array_values(array_unique([...$changed, 'Supporting documents']));
                }

                if ($changed !== []) {
                    $fresh->forceFill(['lock_version' => $fresh->lock_version + 1])->save();
                }

                $this->audit('finance.pv_updated', $fresh, 'finance', ['changed_fields' => $changed, 'lock_version' => $fresh->lock_version]);
                $this->afterCommit($fresh, null);

                DB::afterCommit(fn () => array_map($this->files->deleteFile(...), $removed));

                return $fresh;
            });
        } catch (\Throwable $exception) {
            array_map($this->files->deleteFile(...), $stored);

            throw $exception;
        }
    }

    /** Draft -> With Audit (round 1). */
    public function submit(User $actor, Pv|int $pv, int $expectedVersion): Pv
    {
        $this->authorise($actor, 'finance.create_pv');

        return DB::transaction(function () use ($actor, $pv, $expectedVersion) {
            $fresh = $this->lockedPv($actor, $pv, FinanceScope::AS_FINANCE);
            $this->assertStatus($fresh, [Pv::DRAFT], 'Only a draft can be sent to Audit.');
            $this->assertVersion($fresh, $expectedVersion);

            $from = $fresh->status;
            $fresh->forceFill([
                'status' => Pv::WITH_AUDIT,
                'round' => 1,
                'submitted_at' => now(),
                'last_submitted_at' => now(),
                'submitted_snapshot' => $fresh->contentSnapshot(),
            ])->save();

            $this->record($fresh, FinPvEvent::SUBMITTED, $actor);
            $this->audit('finance.pv_submitted', $fresh, 'finance', ['from' => $from, 'to' => $fresh->status]);
            $this->afterCommit($fresh, FinPvEvent::SUBMITTED, $actor);

            return $fresh;
        });
    }

    /** Queried -> With Audit (round + 1), recording what changed since Audit last saw it. $note is Finance's optional reply. */
    public function resubmit(User $actor, Pv|int $pv, int $expectedVersion, ?string $note = null): Pv
    {
        $this->authorise($actor, 'finance.create_pv');
        $note = $this->optionalText($note, 1000, 'note');

        return DB::transaction(function () use ($actor, $pv, $expectedVersion, $note) {
            $fresh = $this->lockedPv($actor, $pv, FinanceScope::AS_FINANCE);
            $this->assertStatus($fresh, [Pv::QUERIED], 'Only a queried voucher can be resubmitted.');
            $this->assertVersion($fresh, $expectedVersion);

            $current = $fresh->contentSnapshot();
            $changes = $this->changesList($fresh->submitted_snapshot ?? [], $current);
            $from = $fresh->status;

            $fresh->forceFill([
                'status' => Pv::WITH_AUDIT,
                'round' => $fresh->round + 1,
                'last_submitted_at' => now(),
                'submitted_snapshot' => $current,
            ])->save();

            $this->record($fresh, FinPvEvent::RESUBMITTED, $actor, comment: $note, changes: $changes);
            $this->audit('finance.pv_resubmitted', $fresh, 'finance', ['from' => $from, 'to' => $fresh->status, 'changed_fields' => array_column($changes, 'field')]);
            $this->afterCommit($fresh, FinPvEvent::RESUBMITTED, $actor);

            return $fresh;
        });
    }

    /** Audit acknowledged -> Completed. Needs finance.acknowledge; the submitter may do this (it is Finance's own step). */
    public function financeAcknowledge(User $actor, Pv|int $pv): Pv
    {
        $this->authorise($actor, 'finance.acknowledge');

        return DB::transaction(function () use ($actor, $pv) {
            $fresh = $this->lockedPv($actor, $pv, FinanceScope::AS_FINANCE);
            $this->assertStatus($fresh, [Pv::AUDIT_ACKNOWLEDGED], 'Audit has not acknowledged this voucher (or Finance already has).');

            $from = $fresh->status;
            $fresh->forceFill(['status' => Pv::COMPLETED, 'completed_at' => now()])->save();

            $this->record($fresh, FinPvEvent::FINANCE_ACKNOWLEDGED, $actor);
            $this->audit('finance.pv_acknowledged', $fresh, 'finance', ['from' => $from, 'to' => $fresh->status]);
            $this->afterCommit($fresh, FinPvEvent::FINANCE_ACKNOWLEDGED, $actor);

            return $fresh;
        });
    }

    /** Void with a reason, never a delete: from Draft, Queried or With Audit. Audit acknowledged must be withdrawn first. */
    public function void(User $actor, Pv|int $pv, string $reason): Pv
    {
        $this->authorise($actor, 'finance.void_pv');
        $reason = $this->requiredText($reason, 500, 'reason', 'Say why this voucher is being voided.');

        return DB::transaction(function () use ($actor, $pv, $reason) {
            $fresh = $this->lockedPv($actor, $pv, FinanceScope::AS_FINANCE);

            if (! $fresh->isVoidable()) {
                throw ValidationException::withMessages(['reason' => match ($fresh->status) {
                    Pv::AUDIT_ACKNOWLEDGED => 'Audit has acknowledged this voucher. Ask Audit to withdraw its tick before it is voided.',
                    Pv::COMPLETED => 'A completed voucher is final and cannot be voided.',
                    default => 'This voucher is already voided.',
                }]);
            }

            $from = $fresh->status;
            $fresh->forceFill(['status' => Pv::VOIDED, 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();

            $this->record($fresh, FinPvEvent::VOIDED, $actor, comment: $reason);
            $this->audit('finance.pv_voided', $fresh, 'finance', ['from' => $from, 'to' => $fresh->status]);
            $this->afterCommit($fresh, FinPvEvent::VOIDED, $actor);

            return $fresh;
        });
    }

    // ------------------------------------------------------------------ Audit: review

    /** With Audit -> Audit acknowledged (the tick). */
    public function auditAcknowledge(User $actor, Pv|int $pv): Pv
    {
        $this->authorise($actor, 'audit.review');

        return DB::transaction(function () use ($actor, $pv) {
            $fresh = $this->lockedPv($actor, $pv, FinanceScope::AS_AUDIT);
            $this->assertStatus($fresh, [Pv::WITH_AUDIT], 'This voucher is not waiting for Audit.');
            $this->assertNotSubmitter($actor, $fresh);

            $from = $fresh->status;
            $fresh->forceFill(['status' => Pv::AUDIT_ACKNOWLEDGED])->save();

            $this->record($fresh, FinPvEvent::AUDIT_ACKNOWLEDGED, $actor);
            $this->audit('audit.pv_acknowledged', $fresh, 'audit', ['from' => $from, 'to' => $fresh->status]);
            $this->afterCommit($fresh, FinPvEvent::AUDIT_ACKNOWLEDGED, $actor);

            return $fresh;
        });
    }

    /** With Audit -> Queried (the X). A reason code from the fixed list AND a comment are both required. */
    public function auditQuery(User $actor, Pv|int $pv, string $reasonCode, string $comment): Pv
    {
        $this->authorise($actor, 'audit.review');

        if (! isset(Pv::QUERY_REASONS[$reasonCode])) {
            throw ValidationException::withMessages(['reason_code' => 'Choose why the voucher is queried.']);
        }

        $comment = $this->requiredText($comment, 1000, 'comment', 'Explain what is wrong, so Finance can correct it.');

        return DB::transaction(function () use ($actor, $pv, $reasonCode, $comment) {
            $fresh = $this->lockedPv($actor, $pv, FinanceScope::AS_AUDIT);
            $this->assertStatus($fresh, [Pv::WITH_AUDIT], 'This voucher is not waiting for Audit.');
            $this->assertNotSubmitter($actor, $fresh);

            $from = $fresh->status;
            $fresh->forceFill(['status' => Pv::QUERIED])->save();

            $this->record($fresh, FinPvEvent::QUERIED, $actor, comment: $comment, reasonCode: $reasonCode);
            $this->audit('audit.pv_queried', $fresh, 'audit', ['from' => $from, 'to' => $fresh->status, 'reason_code' => $reasonCode]);
            $this->afterCommit($fresh, FinPvEvent::QUERIED, $actor);

            return $fresh;
        });
    }

    /** Audit acknowledged -> With Audit, with a reason; only while Finance has not yet acknowledged. */
    public function withdrawTick(User $actor, Pv|int $pv, string $reason): Pv
    {
        $this->authorise($actor, 'audit.review');
        $reason = $this->requiredText($reason, 1000, 'reason', 'Say why the tick is being withdrawn.');

        return DB::transaction(function () use ($actor, $pv, $reason) {
            $fresh = $this->lockedPv($actor, $pv, FinanceScope::AS_AUDIT);
            $this->assertStatus($fresh, [Pv::AUDIT_ACKNOWLEDGED], $fresh->status === Pv::COMPLETED
                ? 'Finance has already acknowledged this voucher, so the tick can no longer be withdrawn.'
                : 'There is no Audit tick to withdraw on this voucher.');
            $this->assertNotSubmitter($actor, $fresh);

            $from = $fresh->status;
            $fresh->forceFill(['status' => Pv::WITH_AUDIT, 'last_submitted_at' => now()])->save();

            $this->record($fresh, FinPvEvent::TICK_WITHDRAWN, $actor, comment: $reason);
            $this->audit('audit.pv_tick_withdrawn', $fresh, 'audit', ['from' => $from, 'to' => $fresh->status]);
            $this->afterCommit($fresh, FinPvEvent::TICK_WITHDRAWN, $actor);

            return $fresh;
        });
    }

    /** Whether the person submitted this voucher in any round (so may not review it). Used by screens to hide the buttons. */
    public function hasSubmitted(User $user, Pv $pv): bool
    {
        return FinPvEvent::query()
            ->where('pv_id', $pv->id)
            ->whereIn('action', FinPvEvent::SUBMITTING)
            ->where('actor_user_id', $user->id)
            ->exists();
    }

    // ------------------------------------------------------------------ rules

    protected function authorise(User $actor, string $permission): void
    {
        abort_unless($this->scope->can($actor, $permission), 403, 'You do not have permission to do that.');
    }

    /**
     * Re-reads the voucher with a write lock and checks the actor reaches it ON THAT COPY (as Finance, or as Audit: Audit
     * never finds a draft). The copy the caller holds may be stale, or may have been fetched with a client-supplied id.
     */
    protected function lockedPv(User $actor, Pv|int $pv, string $as): Pv
    {
        $row = Pv::query()->lockForUpdate()->find($pv instanceof Pv ? $pv->id : $pv);
        abort_unless($row && $this->scope->containsPv($actor, $row, $as), 404);

        return $row;
    }

    protected function assertEditable(Pv $pv): void
    {
        if (! $pv->isEditable()) {
            throw ValidationException::withMessages(['status' => 'A voucher can be edited only while it is a draft or has been queried by Audit.']);
        }
    }

    /** @param array<int, string> $allowed */
    protected function assertStatus(Pv $pv, array $allowed, string $message): void
    {
        if (! in_array($pv->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    protected function assertVersion(Pv $pv, int $expected): void
    {
        if ($pv->lock_version !== $expected) {
            throw new PvConflictException;
        }
    }

    protected function assertNotSubmitter(User $actor, Pv $pv): void
    {
        if ($this->hasSubmitted($actor, $pv)) {
            throw ValidationException::withMessages(['action' => 'You sent this voucher to Audit, so you cannot review it.']);
        }
    }

    /**
     * Validates and normalises the fields of a voucher into the columns they are stored in. $existing is the voucher being
     * edited: its payee, category and type stay valid even if they would no longer be offered (a person who has since left,
     * a deactivated type), because they are frozen on it.
     *
     * @param  array{region_id: int, district_id: int|null}  $location
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function clean(User $actor, array $location, array $data, ?Pv $existing): array
    {
        $date = $this->date($data['pv_date'] ?? null);
        $number = $this->number($data['pv_no'] ?? null);
        $key = Pv::normaliseNumber($number);

        $taken = Pv::query()->where('pv_no_key', $key)->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))->exists();

        if ($taken) {
            throw $this->numberUsed();
        }

        [$category, $type] = $this->catalogue($location, $data, $existing);

        return [
            'pv_date' => $date->toDateString(),
            'pv_no' => $number,
            'pv_no_key' => $key,
            ...$this->payee($actor, $location, $data, $existing),
            'category_id' => $category->id,
            'type_id' => $type->id,
            'details' => $this->requiredText($data['details'] ?? null, 2000, 'details', 'Enter the details of the payment.'),
            'amount' => $this->amount($data['amount'] ?? null),
            'remarks' => $this->optionalText($data['remarks'] ?? null, 1000, 'remarks'),
        ];
    }

    /** @return array<string, mixed> */
    protected function payee(User $actor, array $location, array $data, ?Pv $existing): array
    {
        $kind = (string) ($data['payee_kind'] ?? '');

        if (! isset(Pv::PAYEE_KINDS[$kind])) {
            throw ValidationException::withMessages(['payee_kind' => 'Choose who is being paid.']);
        }

        $blank = ['payee_employee_id' => null, 'payee_district_id' => null, 'supplier_id' => null];
        $same = fn (string $column, int $id) => $existing && $existing->payee_kind === $kind && (int) $existing->{$column} === $id;

        if (in_array($kind, [Pv::PAYEE_EMPLOYEE, Pv::PAYEE_RETIREE], true)) {
            $id = (int) ($data['payee_employee_id'] ?? 0);
            $employee = $id > 0 ? $this->scope->employees($actor, $kind, $location)->whereKey($id)->first() : null;
            $employee ??= $same('payee_employee_id', $id) ? Employee::query()->find($id) : null;

            if (! $employee) {
                throw ValidationException::withMessages(['payee_employee_id' => $kind === Pv::PAYEE_EMPLOYEE
                    ? 'Choose a current member of staff of this location.'
                    : 'Choose a former member of staff (retired or left service) of this location.']);
            }

            return ['payee_kind' => $kind, 'payee_name' => $employee->full_name, ...$blank, 'payee_employee_id' => $employee->id];
        }

        if ($kind === Pv::PAYEE_DISTRICT) {
            // Region based only: the districts of the voucher's own location. The id is re-resolved here on every save (the browser's
            // value is never trusted); a voucher being edited keeps the district it already has.
            $id = (int) ($data['payee_district_id'] ?? 0);
            $district = $id > 0 ? $this->scope->districts($actor, $location)->whereKey($id)->first() : null;
            $district ??= $same('payee_district_id', $id) ? District::query()->find($id) : null;

            if (! $district) {
                throw ValidationException::withMessages(['payee_district_id' => 'Choose one of the districts of this location.']);
            }

            return ['payee_kind' => $kind, 'payee_name' => $district->district_name, ...$blank, 'payee_district_id' => $district->id];
        }

        $id = (int) ($data['supplier_id'] ?? 0);
        $supplier = FinSupplier::query()->find($id);

        if (! $supplier || (! $supplier->is_active && ! $same('supplier_id', $id))) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier from the list.']);
        }

        return ['payee_kind' => $kind, 'payee_name' => $supplier->name, ...$blank, 'supplier_id' => $supplier->id];
    }

    /**
     * The category and type must be in the VOUCHER'S location's effective list (a switched-off one is refused for a new choice;
     * the voucher being edited keeps the one it has). The rule is PvCatalogService's.
     *
     * @return array{0: FinPvCategory, 1: FinPvType}
     */
    protected function catalogue(array $location, array $data, ?Pv $existing): array
    {
        return $this->catalogue->resolve($location, (int) ($data['category_id'] ?? 0), (int) ($data['type_id'] ?? 0), $existing);
    }

    protected function date(mixed $value): Carbon
    {
        try {
            $date = Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['pv_date' => 'Enter the date on the voucher.']);
        }

        if ($date->gt(today())) {
            throw ValidationException::withMessages(['pv_date' => 'The voucher date cannot be in the future.']);
        }

        return $date;
    }

    protected function number(mixed $value): string
    {
        $number = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if ($number === '' || mb_strlen($number) > 60) {
            throw ValidationException::withMessages(['pv_no' => 'Enter the PV number (up to 60 characters).']);
        }

        return $number;
    }

    /** Said the same wherever the number is used, so the other voucher (maybe another location's) is never revealed. */
    protected function numberUsed(): ValidationException
    {
        return ValidationException::withMessages(['pv_no' => 'This PV number is already used.']);
    }

    protected function amount(mixed $value): int
    {
        $pesewas = Money::toPesewas($value);

        if ($pesewas === null || $pesewas < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount in cedis, more than zero, with at most two decimals (for example 1250.50).']);
        }

        $ceiling = (int) config('gwl.fin_amount_max_cedis') * 100;

        if ($ceiling > 0 && $pesewas > $ceiling) {
            throw ValidationException::withMessages(['amount' => 'That amount is above the limit of GH¢ '.Money::format($ceiling).'. Check for an extra digit.']);
        }

        return $pesewas;
    }

    protected function requiredText(mixed $value, int $max, string $field, string $message): string
    {
        $text = trim((string) $value);

        if ($text === '' || mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => $message.' (up to '.$max.' characters)']);
        }

        return $text;
    }

    protected function optionalText(mixed $value, int $max, string $field): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => "Keep it to {$max} characters."]);
        }

        return $text;
    }

    // ------------------------------------------------------------------ comparison

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @return array<string, array{old: string, new: string}>
     */
    protected function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            $old = $before[$field] ?? '';

            if ($old !== $value) {
                $changes[$field] = ['old' => $old, 'new' => $value];
            }
        }

        return $changes;
    }

    /** @return array<int, array{field: string, old: string, new: string}> the shape stored in fin_pv_events.changes */
    protected function changesList(array $before, array $after): array
    {
        $list = [];

        foreach ($this->diff($before, $after) as $field => $change) {
            $list[] = ['field' => $field, ...$change];
        }

        return $list;
    }

    // ------------------------------------------------------------------ writing the trail

    protected function record(Pv $pv, string $action, User $actor, ?string $comment = null, ?string $reasonCode = null, ?array $changes = null): FinPvEvent
    {
        return FinPvEvent::query()->create([
            'pv_id' => $pv->id,
            'round' => $pv->round,
            'action' => $action,
            'actor_user_id' => $actor->id,
            'comment' => $comment,
            'query_reason_code' => $reasonCode,
            'changes' => $changes === [] ? null : $changes,
        ]);
    }

    /** Ids, statuses and counts only: never the payee, amount, a comment, a reason or a file name. */
    protected function audit(string $action, Pv $pv, string $module, array $metadata = []): void
    {
        AuditLog::record($action, $module, $pv->getTable(), (int) $pv->getKey(), null, null, $metadata + [
            'region_id' => $pv->region_id,
            'district_id' => $pv->district_id,
            'round' => $pv->round,
        ]);
    }

    /** After the commit: stale cached analytics are dropped (books version) and the notices are sent. */
    protected function afterCommit(Pv $pv, ?string $action, ?User $actor = null): void
    {
        $place = ['region_id' => (int) $pv->region_id, 'district_id' => $pv->district_id !== null ? (int) $pv->district_id : null];
        $pvId = $pv->id;
        $round = $pv->round;
        $actorId = $actor?->id;

        DB::afterCommit(function () use ($place, $pvId, $action, $round, $actorId) {
            FinanceBooksVersion::touch($place);

            if ($action !== null && $actorId !== null) {
                event(new PaymentVoucherActioned($pvId, $action, $round, $actorId));
            }
        });
    }
}
