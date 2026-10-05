<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The letterhead of one location: a region, or Head Office (region_id null). */
class LeaveLetterhead extends Model
{
    protected $fillable = ['region_id', 'region_name', 'address_lines', 'hr_signatory_user_id', 'default_cc', 'updated_by'];

    protected $casts = [
        'address_lines' => 'array',
        'default_cc' => 'array',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function hrSignatory(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_signatory_user_id');
    }

    public function isHeadOffice(): bool
    {
        return $this->region_id === null;
    }

    /** No address entered yet: the editor flags it. */
    public function addressMissing(): bool
    {
        return collect($this->address_lines ?? [])->filter(fn ($line) => filled($line))->isEmpty();
    }
}
