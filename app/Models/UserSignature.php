<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved signature: an encrypted PNG on the private signature disk (never a public URL). `storage_path`, the bytes and
 * the hash are never shown or logged; see App\Services\Leave\SignatureService.
 */
class UserSignature extends Model
{
    public const METHOD_DRAWN = 'drawn';

    public const METHOD_UPLOADED = 'uploaded';

    protected $fillable = ['user_id', 'storage_path', 'sha256', 'width', 'height', 'method', 'is_active', 'revoked_at', 'revoked_by'];

    protected $hidden = ['storage_path', 'sha256'];

    protected $casts = [
        'is_active' => 'boolean',
        'revoked_at' => 'datetime',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
