<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Billed customers and their consumption per consumption band, per (district, service category) of one Detailed Billing Report batch. */
class CommercialBillingDetailBand extends Model
{
    /** The bands, in order: label => [exclusive lower bound, inclusive upper bound or null]. Consumption of 0 is its own band. */
    public const BANDS = ['0' => [null, 0], '1-10' => [0, 10], '11-20' => [10, 20], '21-50' => [20, 50], '51-100' => [50, 100], '101-500' => [100, 500], '500+' => [500, null]];

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['batch_id' => 'integer', 'district_id' => 'integer', 'customers' => 'integer', 'consume' => 'float'];

    /** The band a (non-negative) consumption falls in. */
    public static function bandOf(float $consume): string
    {
        return match (true) {
            $consume <= 0 => '0',
            $consume <= 10 => '1-10',
            $consume <= 20 => '11-20',
            $consume <= 50 => '21-50',
            $consume <= 100 => '51-100',
            $consume <= 500 => '101-500',
            default => '500+',
        };
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommercialImportBatch::class, 'batch_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
