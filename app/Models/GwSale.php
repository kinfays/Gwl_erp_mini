<?php

namespace App\Models;

use App\Models\Concerns\GwBooksRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A day's packs of one product sold at the selling price in force that day (frozen on the row). Money in pesewas. A sale is
 * paid at once ('cash' or 'momo') or on credit, for a named customer who pays later; what is still owed on a credit sale is its amount
 * less the receipts not voided (GwSaleReceipt).
 */
class GwSale extends Model
{
    use GwBooksRow;

    public const CASH = 'cash';

    /** Mobile money: paid at once, like cash, only the way it was paid is different. */
    public const MOMO = 'momo';

    public const CREDIT = 'credit';

    public const PAYMENT_TYPES = [self::CASH, self::MOMO, self::CREDIT];

    public static function paymentLabel(string $type): string
    {
        return match ($type) {
            self::MOMO => 'MoMo',
            self::CREDIT => 'Credit',
            default => 'Cash',
        };
    }

    protected $fillable = [
        'region_id', 'district_id', 'sale_date', 'product_id', 'packs', 'unit_price', 'amount', 'payment_type', 'customer_name', 'customer_phone',
        'note', 'status', 'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $attributes = ['payment_type' => self::CASH];

    protected $casts = [
        'region_id' => 'integer',
        'district_id' => 'integer',
        'sale_date' => 'date',
        'packs' => 'integer',
        'unit_price' => 'integer',
        'amount' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(GwProduct::class, 'product_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GwSaleReceipt::class, 'sale_id');
    }

    /**
     * What the Status column says: voided, completed (paid at once, or a credit sale paid in full), partial (some of a credit
     * sale has come in) or pending (nothing yet). Worked out from the sale and its receipts; the stored status stays posted/voided.
     */
    public function paymentStatus(): string
    {
        if ($this->isVoided()) {
            return 'voided';
        }

        if (! $this->isCredit() || $this->balanceOwed() === 0) {
            return 'completed';
        }

        return $this->receivedAmount() > 0 ? 'partial' : 'pending';
    }

    public function isCredit(): bool
    {
        return $this->payment_type === self::CREDIT;
    }

    /** Money received so far (receipts not voided). Reads the loaded relation when there is one. */
    public function receivedAmount(): int
    {
        $receipts = $this->relationLoaded('receipts') ? $this->receipts : $this->receipts()->get();

        return (int) $receipts->reject(fn (GwSaleReceipt $receipt) => $receipt->isVoided())->sum('amount');
    }

    /** What the customer still owes on this sale: nothing for a cash or voided sale. */
    public function balanceOwed(): int
    {
        return $this->isCredit() && ! $this->isVoided() ? max(0, $this->amount - $this->receivedAmount()) : 0;
    }
}
