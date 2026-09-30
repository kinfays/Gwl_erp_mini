<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A transmittal: several letters handed from one holder to one recipient at once. The letters themselves travel as
 * ordinary RoutingHistory hops that point back here (routingHistories); this row groups them and numbers the sheet.
 */
class LetterDispatchBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_no',
        'from_secretariat_id',
        'to_secretariat_id',
        'note',
        'letters_count',
        'confirmed_count',
        'dispatched_at',
        'completed_at',
    ];

    protected $casts = [
        'from_secretariat_id' => 'integer',
        'to_secretariat_id' => 'integer',
        'letters_count' => 'integer',
        'confirmed_count' => 'integer',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function fromSecretariat(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_secretariat_id');
    }

    public function toSecretariat(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_secretariat_id');
    }

    public function routingHistories(): HasMany
    {
        return $this->hasMany(RoutingHistory::class, 'batch_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(LetterNotification::class, 'batch_id');
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function pendingCount(): int
    {
        return max(0, $this->letters_count - $this->confirmed_count);
    }

    /** 'TR-<year>-<id padded to 6>'. Derived from the id, so it cannot collide the way a counter can. */
    public static function numberFor(int $id, ?\DateTimeInterface $dispatchedAt = null): string
    {
        return 'TR-'.($dispatchedAt ?? now())->format('Y').'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
