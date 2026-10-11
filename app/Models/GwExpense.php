<?php

namespace App\Models;

use App\Models\Concerns\GwBooksRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cost of the trade in pesewas. Fuel paid out of pocket is the only reimbursable kind; loading and offloading are paid in
 * cash and stay not_applicable.
 */
class GwExpense extends Model
{
    use GwBooksRow;

    public const FUEL = 'fuel';

    public const LOADING = 'loading';

    public const OFFLOADING = 'offloading';

    public const OTHER = 'other';

    public const CATEGORIES = [self::FUEL, self::LOADING, self::OFFLOADING, self::OTHER];

    protected $fillable = [
        'region_id', 'district_id', 'expense_date', 'category', 'amount', 'purchase_id', 'payee', 'receipt_ref', 'note',
        'paid_by_user_id', 'reimbursement_status', 'reimbursed_on', 'reimbursement_ref', 'reimbursed_by_user_id', 'status',
        'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'expense_date' => 'date',
        'amount' => 'integer',
        'reimbursed_on' => 'date',
        'voided_at' => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(GwPurchase::class, 'purchase_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
