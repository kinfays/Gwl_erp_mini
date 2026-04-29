<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterStatusLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'letter_id',
        'secretariat_id',
        'status',
        'is_closed',
        'out_date',
    ];

    protected $casts = [
        'letter_id' => 'integer',
        'secretariat_id' => 'integer',
        'is_closed' => 'boolean',
        'out_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function letter(): BelongsTo
    {
        return $this->belongsTo(MailLetter::class, 'letter_id');
    }

    public function secretariat(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'secretariat_id');
    }
}
