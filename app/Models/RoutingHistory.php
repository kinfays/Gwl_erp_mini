<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

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
