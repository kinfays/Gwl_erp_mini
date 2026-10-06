<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One route of a billing summary. Volumes are in thousand litres, amounts in GH¢. */
class CommercialBillingRoute extends Model
{
    /** Amount columns, in the order the source prints them. */
    public const AMOUNT_FIELDS = [
        'opening_balance',
        'billing_for_period',
        'total_receivable',
        'revenue_adjustment',
        'payment_for_month',
        'prev_month_payment',
        'offset_payments',
        'total_payments',
        'closing_balance',
    ];

    public const VOLUME_FIELDS = [
        'volume_actual',
        'volume_average',
        'volume_total',
    ];

    public const COUNT_FIELDS = [
        'customers_count',
        'billed_average_metered',
        'billed_average_unmetered',
        'billed_actual_reading',
        'billed_total',
        'unbilled_suspense_metered',
        'unbilled_suspense_unmetered',
        'unbilled_disconn_metered',
        'unbilled_disconn_unmetered',
        'unbilled_other',
        'unbilled_total',
    ];

    protected $fillable = [
        'batch_id',
        'district_id',
        'district_label_raw',
        'route_code',
        'volume_actual',
        'volume_average',
        'volume_total',
        'opening_balance',
        'billing_for_period',
        'total_receivable',
        'revenue_adjustment',
        'payment_for_month',
        'prev_month_payment',
        'offset_payments',
        'total_payments',
        'closing_balance',
        'customers_count',
        'billed_average_metered',
        'billed_average_unmetered',
        'billed_actual_reading',
        'billed_total',
        'unbilled_suspense_metered',
        'unbilled_suspense_unmetered',
        'unbilled_disconn_metered',
        'unbilled_disconn_unmetered',
        'unbilled_other',
        'unbilled_total',
    ];

    protected function casts(): array
    {
        $casts = [
            'batch_id' => 'integer',
            'district_id' => 'integer',
        ];

        foreach ([...self::AMOUNT_FIELDS, ...self::VOLUME_FIELDS] as $field) {
            $casts[$field] = 'decimal:2';
        }

        foreach (self::COUNT_FIELDS as $field) {
            $casts[$field] = 'integer';
        }

        return $casts;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * For each (region, customer segment, period) the most recent non-voided billing batch wins as a whole
     * snapshot (design section 2.3).
     */
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
