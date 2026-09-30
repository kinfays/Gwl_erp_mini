<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scanned copy of a letter's hardcopy. The file is on a private disk and is only ever served through
 * LetterScanController (which checks who may see the letter); there is no public URL.
 */
class LetterScan extends Model
{
    public const KINDS = [
        'original' => 'Original',
        'commented' => 'With comments',
        'enclosure' => 'Enclosure',
    ];

    protected $fillable = [
        'letter_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'sha256',
        'note',
        'uploaded_by_id',
        'voided_at',
        'voided_by_id',
        'void_reason',
    ];

    protected $casts = [
        'letter_id' => 'integer',
        'size_bytes' => 'integer',
        'uploaded_by_id' => 'integer',
        'voided_by_id' => 'integer',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** Scans that are still visible: not voided. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function letter(): BelongsTo
    {
        return $this->belongsTo(MailLetter::class, 'letter_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'voided_by_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    public function humanSize(): string
    {
        // Plain arithmetic: Illuminate\Support\Number needs PHP's intl extension, which a server may not have.
        $bytes = $this->size_bytes;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return $bytes < 1024 * 1024
            ? round($bytes / 1024, 1).' KB'
            : round($bytes / 1024 / 1024, 1).' MB';
    }
}
