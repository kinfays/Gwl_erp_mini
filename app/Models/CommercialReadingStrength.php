<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Verified customer strength for one month. Region-wide: it is the same figure on every reader sheet of the source. */
class CommercialReadingStrength extends Model
{
    protected $fillable = [
        'batch_id',
        'month',
        'verified_strength',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'month' => 'date',
        'verified_strength' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }

    /**
     * The latest non-voided batch of the region that carries this month wins (design section 2.3).
     */
    public function scopeEffective(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query
            ->whereHas('batch', fn (Builder $batch) => $batch->notVoided())
            ->whereNotExists(function ($newer) use ($table) {
                $newer->selectRaw('1')
                    ->from("{$table} as newer_strengths")
                    ->join('commercial_import_batches as newer_batches', 'newer_batches.id', '=', 'newer_strengths.batch_id')
                    ->join('commercial_import_batches as own_batches', 'own_batches.id', '=', "{$table}.batch_id")
                    ->whereRaw("DATE(newer_strengths.month) = DATE({$table}.month)")
                    ->whereColumn('newer_batches.region_id', 'own_batches.region_id')
                    ->whereColumn('newer_batches.id', '>', 'own_batches.id')
                    ->where('newer_batches.status', '!=', CommercialImportBatch::STATUS_VOIDED);
            });
    }
}
