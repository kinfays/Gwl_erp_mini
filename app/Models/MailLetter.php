<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailLetter extends Model
{
    use HasFactory;

    protected $fillable = [
        'sn_number',
        'subject',
        'ref_no',
        'type',
        'memo_sender_id',
        'company_sender',
        'date_on_letter',
        'region_id',
        'created_by_id',
    ];

    protected $casts = [
        'memo_sender_id' => 'integer',
        'region_id' => 'integer',
        'created_by_id' => 'integer',
        'date_on_letter' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function memoSender(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'memo_sender_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(LetterStatusLog::class, 'letter_id');
    }

    public function routingHistories(): HasMany
    {
        return $this->hasMany(RoutingHistory::class, 'letter_id');
    }

    public function remarks(): HasMany
    {
        return $this->hasMany(LetterRemark::class, 'letter_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(LetterNotification::class, 'letter_id');
    }

    public function latestStatusFor(?Employee $employee): ?LetterStatusLog
    {
        if (! $employee) {
            return null;
        }

        if ($this->relationLoaded('statusLogs')) {
            return $this->statusLogs
                ->where('secretariat_id', $employee->id)
                ->sortByDesc('created_at')
                ->first();
        }

        return $this->statusLogs()
            ->where('secretariat_id', $employee->id)
            ->latest()
            ->first();
    }

    public function getSenderNameAttribute(): string
    {
        return $this->type === 'Internal'
            ? ($this->memoSender?->full_name ?? 'Internal sender')
            : ($this->company_sender ?: 'External sender');
    }
}
