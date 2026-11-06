<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IctAssetAuditLine extends Model
{
    public const RESULT_MATCHED = 'matched';
    public const RESULT_MISMATCH = 'mismatch';
    public const RESULT_NOT_FOUND = 'not_found';
    /** Found, but physically damaged. Its own category: never counted as matched, mismatch or not found. */
    public const RESULT_DAMAGED = 'damaged';

    public const RESULTS = [
        self::RESULT_MATCHED,
        self::RESULT_MISMATCH,
        self::RESULT_NOT_FOUND,
        self::RESULT_DAMAGED,
    ];

    /** The one place a result is named for people (screens, exports, PDF). A NULL result is "Pending". */
    public const RESULT_LABELS = [
        self::RESULT_MATCHED => 'Matched',
        self::RESULT_MISMATCH => 'Mismatch',
        self::RESULT_NOT_FOUND => 'Not found',
        self::RESULT_DAMAGED => 'Damaged',
    ];

    /** Badge tone per result (x-ui.status-pill), each with a dark-mode token; Pending is muted. Damaged shares Not found's alarm tone. */
    public const RESULT_TONES = [
        self::RESULT_MATCHED => 'success',
        self::RESULT_MISMATCH => 'warning',
        self::RESULT_NOT_FOUND => 'danger',
        self::RESULT_DAMAGED => 'danger',
    ];

    public static function labelFor(?string $result): string
    {
        return self::RESULT_LABELS[$result] ?? 'Pending';
    }

    public static function toneFor(?string $result): string
    {
        return self::RESULT_TONES[$result] ?? 'muted';
    }

    protected $table = 'ict_asset_audit_lines';

    protected $fillable = [
        'ict_asset_audit_id',
        'ict_asset_id',
        'expected_status',
        'expected_assigned_to_employee_id',
        'expected_district_id',
        'result',
        'actual_status',
        'actual_assigned_to_employee_id',
        'actual_district_id',
        'actual_location',
        'mismatch_reason',
        'verified_by_user_id',
        'verified_at',
        'correction_applied',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'correction_applied' => 'boolean',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(IctAssetAudit::class, 'ict_asset_audit_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(IctAsset::class, 'ict_asset_id');
    }

    public function expectedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'expected_assigned_to_employee_id');
    }

    public function expectedDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'expected_district_id');
    }

    public function actualEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actual_assigned_to_employee_id');
    }

    public function actualDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'actual_district_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->result === null;
    }
}
