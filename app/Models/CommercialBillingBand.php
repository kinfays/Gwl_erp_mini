<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One consumption band of the domestic (category 611) breakdown that closes a billing summary. */
class CommercialBillingBand extends Model
{
    protected $fillable = [
        'batch_id',
        'category_code',
        'band',
        'customers',
        'volume',
        'amount',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'customers' => 'integer',
        'volume' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }

    /** Same snapshot rule as CommercialBillingRoute::scopeEffective(). */
    public function scopeEffective(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query
            ->whereHas('batch', fn (Builder $batch) => $batch->notVoided())
            ->whereNotExists(function ($newer) use ($table) {
                $newer->selectRaw('1')
                    ->from('commercial_import_batches as newer_batches')
                    ->join('commercial_import_batches as own_batches', 'own_batches.id', '=', "{$table}.batch_id")
                    ->whereColumn('newer_batches.id', '>', 'own_batches.id')
                    ->whereColumn('newer_batches.report_type', 'own_batches.report_type')
                    ->whereColumn('newer_batches.region_id', 'own_batches.region_id')
                    ->whereColumn('newer_batches.customer_segment', 'own_batches.customer_segment')
                    ->whereRaw('DATE(newer_batches.period_from) = DATE(own_batches.period_from)')
                    ->whereRaw('DATE(newer_batches.period_to) = DATE(own_batches.period_to)')
                    ->where('newer_batches.status', '!=', CommercialImportBatch::STATUS_VOIDED);
            });
    }
}
