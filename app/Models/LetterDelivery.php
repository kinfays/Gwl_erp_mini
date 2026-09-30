<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The hand-over of the hardcopy to its addressee: who took it (a staff member, or an outside party by name), who gave it, when. */
class LetterDelivery extends Model
{
    protected $fillable = [
        'letter_id',
        'delivered_to_employee_id',
        'delivered_to_name',
        'delivered_by_id',
        'delivered_at',
        'note',
    ];

    protected $casts = [
        'letter_id' => 'integer',
        'delivered_to_employee_id' => 'integer',
        'delivered_by_id' => 'integer',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function letter(): BelongsTo
    {
        return $this->belongsTo(MailLetter::class, 'letter_id');
    }

    public function deliveredTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delivered_to_employee_id');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delivered_by_id');
    }

    /** Who received it, for display: the staff member's name, or the name that was typed. */
    public function addresseeName(): string
    {
        return $this->deliveredTo?->full_name ?? $this->delivered_to_name ?? '-';
    }
}
