<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reader's counts for one month. Percentages are not stored: the source measures them against the whole region's
 * strength, which says nothing about a single reader. Rates are recomputed from these counts.
 */
class CommercialReadingStat extends Model
{
    public const MATCH_MATCHED = 'matched';
    public const MATCH_UNMATCHED = 'unmatched';
    public const MATCH_SYSTEM_ACCOUNT = 'system_account';

    public const MATCH_STATUSES = [
        self::MATCH_MATCHED,
        self::MATCH_UNMATCHED,
        self::MATCH_SYSTEM_ACCOUNT,
    ];

    protected $fillable = [
        'batch_id',
        'month',
        'reader_staff_id',
        'reader_name_raw',
        'employee_id',
        'district_id',
        'read_count',
        'skipped_count',
        'visited_count',
        'match_status',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'month' => 'date',
        'employee_id' => 'integer',
        'district_id' => 'integer',
        'read_count' => 'integer',
        'skipped_count' => 'integer',
        'visited_count' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function isSystemAccount(): bool
    {
        return $this->match_status === self::MATCH_SYSTEM_ACCOUNT;
    }

    /** Real readers only: the system account is imported (it has real counts) but never ranked or counted as a reader. */
    public function scopeReaders(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('match_status'), '!=', self::MATCH_SYSTEM_ACCOUNT);
    }

    /**
     * For each (region, month, reader) the row of the most recent non-voided batch that holds that reader-month wins, so
     * a weekly upload refreshes the current month while earlier months keep their latest known value (design section 2.3).
     */
    public function scopeEffective(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query
            ->whereHas('batch', fn (Builder $batch) => $batch->notVoided())
            ->whereNotExists(function ($newer) use ($table) {
                $newer->selectRaw('1')
                    ->from("{$table} as newer_stats")
                    ->join('commercial_import_batches as newer_batches', 'newer_batches.id', '=', 'newer_stats.batch_id')
                    ->join('commercial_import_batches as own_batches', 'own_batches.id', '=', "{$table}.batch_id")
                    ->whereRaw("DATE(newer_stats.month) = DATE({$table}.month)")
                    ->whereColumn('newer_stats.reader_staff_id', "{$table}.reader_staff_id")
                    ->whereColumn('newer_batches.region_id', 'own_batches.region_id')
                    ->whereColumn('newer_batches.id', '>', 'own_batches.id')
                    ->where('newer_batches.status', '!=', CommercialImportBatch::STATUS_VOIDED);
            });
    }
}
