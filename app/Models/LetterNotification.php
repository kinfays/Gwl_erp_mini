<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'message',
        'secretariat_id',
        'letter_id',
        'is_read',
    ];

    protected $casts = [
        'secretariat_id' => 'integer',
        'letter_id' => 'integer',
        'is_read' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function secretariat(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'secretariat_id');
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(MailLetter::class, 'letter_id');
    }
}
