<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterRemark extends Model
{
    use HasFactory;

    protected $fillable = [
        'letter_id',
        'author_id',
        'remark_secretariat_id',
        'remark_content',
        'created_by_id',
    ];

    protected $casts = [
        'letter_id' => 'integer',
        'author_id' => 'integer',
        'remark_secretariat_id' => 'integer',
        'created_by_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function letter(): BelongsTo
    {
        return $this->belongsTo(MailLetter::class, 'letter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }

    public function remarkSecretariat(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'remark_secretariat_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_id');
    }
}
