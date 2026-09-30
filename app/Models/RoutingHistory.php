<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'letter_id',
        'from_secretariat_id',
        'to_secretariat_id',
        'batch_id',
        'received_confirm',
        'confirmed_at',
        'confirmed_by_id',
        'resolution',
        'resolved_at',
        'resolution_note',
        'reminded_at',
    ];

    protected $casts = [
        'letter_id' => 'integer',
        'from_secretariat_id' => 'integer',
        'to_secretariat_id' => 'integer',
        'batch_id' => 'integer',
        'confirmed_by_id' => 'integer',
        'received_confirm' => 'boolean',
        'confirmed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reminded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** Not recalled or rejected: the hop is still (or was) a real hand-over. */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolution');
    }

    /**
     * "Awaiting confirmation": not confirmed and not recalled/rejected. Every place that used to read
     * received_confirm = 0 as pending goes through this, so a resolved hop never looks pending, never blocks
     * canDispatch() and never blocks close().
     */
    public function scopeAwaiting(Builder $query): Builder
    {
        return $query->where('received_confirm', false)->whereNull('resolution');
    }

    /** Awaiting for at least $days days (the alert threshold). */
    public function scopeOverdue(Builder $query, int $days): Builder
    {
        return $query->awaiting()->where('created_at', '<=', now()->subDays($days));
    }

    public function isResolved(): bool
    {
        return $this->resolution !== null;
    }

    public function isAwaiting(): bool
    {
        return ! $this->received_confirm && ! $this->isResolved();
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(MailLetter::class, 'letter_id');
    }

    public function fromSecretariat(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_secretariat_id');
    }

    public function toSecretariat(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_secretariat_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LetterDispatchBatch::class, 'batch_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'confirmed_by_id');
    }
}
