<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The approval letter of a leave request. Everything printed on it is frozen in `snapshot` when it is generated, so
 * editing the letterhead or the board later never changes an issued letter. Only reference_no, the cc list, the signatory
 * mode and the Christmas line can change, and only until the first print.
 */
class LeaveLetter extends Model
{
    protected $fillable = [
        'leave_request_id', 'reference_no', 'issued_at', 'snapshot', 'signatory_mode', 'signer_user_id',
        'signature_authorized', 'signature_id', 'printed_count', 'first_printed_at', 'last_printed_by',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'snapshot' => 'array',
        'signature_authorized' => 'boolean',
        'printed_count' => 'integer',
        'first_printed_at' => 'datetime',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(UserSignature::class, 'signature_id');
    }

    /** Once printed, the editable fields lock. */
    public function isLocked(): bool
    {
        return $this->first_printed_at !== null;
    }
}
