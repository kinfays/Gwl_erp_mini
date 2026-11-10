<?php

namespace App\Models;

use App\Services\Finance\PvCatalogService;
use App\Services\GWater\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A payment voucher (PV): prepared by Finance, acknowledged by Audit, acknowledged back by Finance. Every change goes
 * through PaymentVoucherWorkflowService; who may read one is FinanceScope's decision (location, and drafts for Finance only).
 *
 *   Draft -> With Audit -> (Audit tick) Audit acknowledged -> (Finance tick) Completed
 *   With Audit -> (Audit X) Queried -> (Finance corrects + resubmits) With Audit, round + 1 ...
 *   Void (with a reason, never a delete) from Draft, Queried or With Audit.
 */
class FinPaymentVoucher extends Model
{
    public const DRAFT = 'draft';

    public const WITH_AUDIT = 'with_audit';

    public const QUERIED = 'queried';

    public const AUDIT_ACKNOWLEDGED = 'audit_acknowledged';

    public const COMPLETED = 'completed';

    public const VOIDED = 'voided';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::WITH_AUDIT => 'With Audit',
        self::QUERIED => 'Queried',
        self::AUDIT_ACKNOWLEDGED => 'Audit acknowledged',
        self::COMPLETED => 'Completed',
        self::VOIDED => 'Voided',
    ];

    public const PAYEE_EMPLOYEE = 'employee';

    public const PAYEE_RETIREE = 'retiree';

    public const PAYEE_DISTRICT = 'district';

    public const PAYEE_SUPPLIER = 'supplier';

    public const PAYEE_KINDS = [
        self::PAYEE_EMPLOYEE => 'Employee',
        self::PAYEE_RETIREE => 'Retiree',
        self::PAYEE_DISTRICT => 'District',
        self::PAYEE_SUPPLIER => 'Supplier',
    ];

    /** Why Audit queried a voucher: a fixed list, always with a free-text comment as well. */
    public const QUERY_REASONS = [
        'amount_mismatch' => 'Amount does not match',
        'missing_document' => 'Missing supporting document',
        'wrong_category' => 'Wrong category / type',
        'duplicate' => 'Duplicate',
        'payee_not_eligible' => 'Payee not eligible',
        'calculation_error' => 'Calculation error',
        'other' => 'Other',
    ];

    protected $table = 'fin_pvs';

    protected $fillable = [
        'region_id', 'district_id', 'pv_date', 'pv_no', 'pv_no_key', 'payee_kind', 'payee_name', 'payee_employee_id',
        'payee_district_id', 'supplier_id', 'category_id', 'type_id', 'details', 'amount', 'remarks', 'status', 'round',
        'lock_version', 'created_by', 'submitted_at', 'last_submitted_at', 'completed_at', 'submitted_snapshot',
        'voided_at', 'voided_by', 'void_reason', 'batch_id', 'assigned_to_user_id', 'assigned_by_user_id', 'assigned_at',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'pv_date' => 'date',
        'payee_employee_id' => 'integer',
        'payee_district_id' => 'integer',
        'supplier_id' => 'integer',
        'category_id' => 'integer',
        'type_id' => 'integer',
        'amount' => 'integer',
        'round' => 'integer',
        'lock_version' => 'integer',
        'created_by' => 'integer',
        'submitted_at' => 'datetime',
        'last_submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'voided_at' => 'datetime',
        'batch_id' => 'integer',
        'assigned_to_user_id' => 'integer',
        'assigned_by_user_id' => 'integer',
        'assigned_at' => 'datetime',
        'submitted_snapshot' => 'array',
    ];

    /** The number as compared for uniqueness: trimmed, runs of spaces collapsed, upper-cased. */
    public static function normaliseNumber(?string $number): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $number)));
    }

    /**
     * The category name as THIS voucher's location shows it today (its own rename, else the standard / own name). Lists set it in
     * bulk through PvCatalogService::labelPvs(); otherwise it is worked out for this voucher alone. Never print `category->name`.
     */
    public function getCategoryLabelAttribute(): string
    {
        return $this->attributes['category_label'] ?? app(PvCatalogService::class)->labelsOf($this)['category'];
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->attributes['type_label'] ?? app(PvCatalogService::class)->labelsOf($this)['type'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinPvCategory::class, 'category_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(FinPvType::class, 'type_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FinSupplier::class, 'supplier_id');
    }

    public function payeeEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'payee_employee_id');
    }

    public function payeeDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'payee_district_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FinPvBatch::class, 'batch_id');
    }

    /** The Audit officer the manager gave this voucher to (null = awaiting assignment, or never sent). */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function isAssigned(): bool
    {
        return $this->assigned_to_user_id !== null;
    }

    public function events(): HasMany
    {
        return $this->hasMany(FinPvEvent::class, 'pv_id')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FinPvAttachment::class, 'pv_id')->orderBy('id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? Str::headline((string) $this->status);
    }

    public function payeeKindLabel(): string
    {
        return self::PAYEE_KINDS[$this->payee_kind] ?? Str::headline((string) $this->payee_kind);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isVoided(): bool
    {
        return $this->status === self::VOIDED;
    }

    /** Finance edits only while the voucher is theirs: in Draft or after Audit queried it. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::QUERIED], true);
    }

    /**
     * Why this voucher cannot go into a (new) batch, or null when it can: only a Draft that is in no batch. The ONE rule: the batch
     * service refuses with this sentence and the Finance list uses it to say why a row cannot be ticked.
     */
    public function batchBlocker(): ?string
    {
        if ($this->status !== self::DRAFT) {
            return $this->status === self::VOIDED ? 'is voided' : 'is not a draft (it has been sent to Audit)';
        }

        return $this->batch_id !== null ? 'is already in batch '.($this->batch?->batch_no ?? '') : null;
    }

    public function isVoidable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::QUERIED, self::WITH_AUDIT], true);
    }

    /** Whether Audit has (or once had) it in front of them: never a draft, and not a voided draft. */
    public function reachedAudit(): bool
    {
        return $this->submitted_at !== null;
    }

    public function amountFormatted(): string
    {
        return Money::format($this->amount);
    }

    /**
     * The fields as a person sees them, keyed by label, for the "changed since last query" comparison. Plain strings only.
     *
     * @return array<string, string>
     */
    public function contentSnapshot(): array
    {
        $labels = app(PvCatalogService::class)->labelsOf($this);

        return [
            'PV date' => $this->pv_date?->format('d M Y') ?? '',
            'PV number' => (string) $this->pv_no,
            'Payee' => $this->payeeKindLabel().': '.$this->payee_name,
            'Category' => $labels['category'],
            'Type' => $labels['type'],
            'Details' => (string) $this->details,
            'Amount (GH¢)' => Money::format($this->amount),
            'Remarks' => (string) $this->remarks,
            'Supporting documents' => $this->attachments()->pluck('display_name')->implode(', '),
        ];
    }

    /**
     * The two marks of the list: what Audit has done and what Finance has done. Each is a state (ticked | queried | waiting |
     * voided | none), a word that is always shown (never colour alone), and who / when / why for the popover. Reads the loaded
     * events (load `events.actor` first to avoid a query per row).
     *
     * @return array{audit: array<string, mixed>, finance: array<string, mixed>}
     */
    public function marks(): array
    {
        $events = $this->events;
        $last = fn (string|array $actions): ?FinPvEvent => $events->last(fn (FinPvEvent $event) => in_array($event->action, (array) $actions, true));
        $from = fn (?FinPvEvent $event, string $state, string $word, string $detail = '') => [
            'state' => $state,
            'word' => $word,
            'by' => $event?->actorName(),
            'at' => $event?->created_at,
            'reason' => $event?->reasonLabel(),
            'comment' => $event?->comment,
            'detail' => $detail,
        ];
        $none = fn (string $word = '-', string $detail = '') => ['state' => 'none', 'word' => $word, 'by' => null, 'at' => null, 'reason' => null, 'comment' => null, 'detail' => $detail];

        return match ($this->status) {
            self::DRAFT => ['audit' => $none('-', 'Not sent to Audit yet.'), 'finance' => $none('Draft', 'Still a draft: not sent to Audit yet.')],
            self::WITH_AUDIT => [
                'audit' => $this->withdrawnAfterLastSubmission($events)
                    ? $from($last(FinPvEvent::TICK_WITHDRAWN), 'waiting', 'Waiting', 'The tick was withdrawn; waiting for Audit again.')
                    : $from($last(FinPvEvent::SUBMITTING), 'waiting', 'Waiting', 'Waiting for Audit.'),
                'finance' => $none('-', 'Nothing for Finance to do yet.'),
            ],
            self::QUERIED => [
                'audit' => $from($last(FinPvEvent::QUERIED), 'queried', 'Queried'),
                'finance' => $from($last(FinPvEvent::QUERIED), 'waiting', 'Waiting', 'Waiting for Finance to correct and resubmit.'),
            ],
            self::AUDIT_ACKNOWLEDGED => [
                'audit' => $from($last(FinPvEvent::AUDIT_ACKNOWLEDGED), 'ticked', 'Acknowledged'),
                'finance' => $from($last(FinPvEvent::AUDIT_ACKNOWLEDGED), 'waiting', 'Waiting', 'Waiting for Finance to acknowledge.'),
            ],
            self::COMPLETED => [
                'audit' => $from($last(FinPvEvent::AUDIT_ACKNOWLEDGED), 'ticked', 'Acknowledged'),
                'finance' => $from($last(FinPvEvent::FINANCE_ACKNOWLEDGED), 'ticked', 'Acknowledged'),
            ],
            default => [
                'audit' => $from($last(FinPvEvent::VOIDED), 'voided', 'Voided'),
                'finance' => $from($last(FinPvEvent::VOIDED), 'voided', 'Voided'),
            ],
        };
    }

    /** @param \Illuminate\Support\Collection<int, FinPvEvent> $events */
    private function withdrawnAfterLastSubmission($events): bool
    {
        // An assignment in between does not change what Audit last did.
        $lastEvent = $events->last(fn (FinPvEvent $event) => ! in_array($event->action, FinPvEvent::ASSIGNING, true));

        return $lastEvent !== null && $lastEvent->action === FinPvEvent::TICK_WITHDRAWN;
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where($query->getModel()->getTable().'.status', $status);
    }

    public function ageInDays(?Carbon $since = null): int
    {
        $since ??= $this->last_submitted_at ?? $this->created_at;

        return (int) $since->diffInDays(now());
    }
}
