<?php

namespace App\Models;

use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PPE a member of staff holds (or held). Open while status is "issued"; closing it (returned, worn out, damaged, lost)
 * is final and the row is then immutable. The state of an open issue (overdue, replacement due, ok) is computed from
 * replace_due_on, never stored.
 */
class HsPpeIssue extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_WORN_OUT = 'worn_out';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_LOST = 'lost';

    public const STATUSES = [
        self::STATUS_ISSUED => 'Issued',
        self::STATUS_RETURNED => 'Returned',
        self::STATUS_WORN_OUT => 'Worn out',
        self::STATUS_DAMAGED => 'Damaged',
        self::STATUS_LOST => 'Lost',
    ];

    /** The ways an open issue is closed. */
    public const OUTCOMES = [
        self::STATUS_RETURNED => 'Returned to store',
        self::STATUS_WORN_OUT => 'Worn out',
        self::STATUS_DAMAGED => 'Damaged',
        self::STATUS_LOST => 'Lost',
    ];

    public const STATE_OVERDUE = 'overdue';
    public const STATE_REPLACEMENT_DUE = 'replacement_due';
    public const STATE_OK = 'ok';

    protected $fillable = [
        'employee_id',
        'ppe_type_id',
        'size',
        'quantity',
        'issued_on',
        'issued_by',
        'replace_due_on',
        'expires_on',
        'is_historic',
        'status',
        'closed_on',
        'close_note',
        'replaced_by_issue_id',
        'acknowledged_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'issued_on' => 'date',
        'replace_due_on' => 'date',
        'expires_on' => 'date',
        'is_historic' => 'boolean',
        'closed_on' => 'date',
        'acknowledged_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(HsPpeType::class, 'ppe_type_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_issue_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(HsPpeStockMovement::class, 'issue_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    /** overdue / replacement_due / ok for an open issue; null for a closed one. */
    public function state(): ?string
    {
        if (! $this->isOpen()) {
            return null;
        }

        if ($this->replace_due_on === null) {
            return self::STATE_OK;
        }

        $due = $this->replace_due_on->copy()->startOfDay();

        return match (true) {
            $due->lt(today()) => self::STATE_OVERDUE,
            $due->lte(today()->addDays((int) HealthSafetySettings::value('hs_expiry_warning_days'))) => self::STATE_REPLACEMENT_DUE,
            default => self::STATE_OK,
        };
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_ISSUED);
    }

    /** Open issues past their replacement date. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull($query->qualifyColumn('replace_due_on'))
            ->whereDate($query->qualifyColumn('replace_due_on'), '<', today()->toDateString());
    }

    /** Open issues whose replacement date falls between today and the warning window. */
    public function scopeReplacementDue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull($query->qualifyColumn('replace_due_on'))
            ->whereDate($query->qualifyColumn('replace_due_on'), '>=', today()->toDateString())
            ->whereDate($query->qualifyColumn('replace_due_on'), '<=', today()->addDays((int) HealthSafetySettings::value('hs_expiry_warning_days'))->toDateString());
    }

    /** Open issues with no replacement date, or one beyond the warning window. */
    public function scopeInDate(Builder $query): Builder
    {
        return $query->open()->where(function (Builder $inDate) use ($query) {
            $column = $query->qualifyColumn('replace_due_on');

            $inDate->whereNull($column)
                ->orWhereDate($column, '>', today()->addDays((int) HealthSafetySettings::value('hs_expiry_warning_days'))->toDateString());
        });
    }

    public function scopeWithState(Builder $query, string $state): Builder
    {
        return match ($state) {
            self::STATE_OVERDUE => $query->overdue(),
            self::STATE_REPLACEMENT_DUE => $query->replacementDue(),
            self::STATE_OK => $query->inDate(),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
