<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdmPolicyApp extends Model
{
    use HasFactory;

    public const INSTALL_TYPE_FORCE_INSTALLED = 'FORCE_INSTALLED';

    public const INSTALL_TYPE_AVAILABLE = 'AVAILABLE';

    public const INSTALL_TYPE_BLOCKED = 'BLOCKED';

    public const INSTALL_TYPE_REQUIRED_FOR_SETUP = 'REQUIRED_FOR_SETUP';

    public const INSTALL_TYPES = [
        self::INSTALL_TYPE_FORCE_INSTALLED => 'Force installed',
        self::INSTALL_TYPE_REQUIRED_FOR_SETUP => 'Required for setup',
        self::INSTALL_TYPE_AVAILABLE => 'Available (user may install)',
        self::INSTALL_TYPE_BLOCKED => 'Blocked',
    ];

    public const PERMISSION_POLICIES = [
        '' => 'Device default',
        'PROMPT' => 'Prompt the user',
        'GRANT' => 'Grant automatically',
        'DENY' => 'Deny automatically',
    ];

    protected $table = 'mdm_policy_apps';

    protected $fillable = [
        'mdm_policy_id',
        'package_name',
        'app_name',
        'install_type',
        'default_permission_policy',
        'is_enabled',
    ];

    protected $casts = [
        'mdm_policy_id' => 'integer',
        'is_enabled' => 'boolean',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(MdmPolicy::class, 'mdm_policy_id');
    }
}
