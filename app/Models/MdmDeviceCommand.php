<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdmDeviceCommand extends Model
{
    use HasFactory;

    public const TYPE_LOCK = 'LOCK';

    public const TYPE_REBOOT = 'REBOOT';

    public const TYPE_RESET_PASSWORD = 'RESET_PASSWORD';

    public const TYPE_START_LOST_MODE = 'START_LOST_MODE';

    public const TYPE_STOP_LOST_MODE = 'STOP_LOST_MODE';

    /** Not an issueCommand call: executed through devices.delete (see CommandService::deliverWipe). */
    public const TYPE_WIPE = 'WIPE';

    public const TYPES = [
        self::TYPE_LOCK => 'Lock',
        self::TYPE_REBOOT => 'Reboot',
        self::TYPE_RESET_PASSWORD => 'Reset passcode',
        self::TYPE_START_LOST_MODE => 'Start Lost Mode',
        self::TYPE_STOP_LOST_MODE => 'Stop Lost Mode',
        self::TYPE_WIPE => 'Wipe',
    ];

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_FAILED = 'failed';

    protected $table = 'mdm_device_commands';

    protected $fillable = [
        'mdm_device_id',
        'type',
        'payload',
        'status',
        'google_operation_name',
        'requested_by',
        'requested_at',
        'sent_at',
        'acknowledged_at',
        'error',
    ];

    protected $casts = [
        'mdm_device_id' => 'integer',
        'payload' => 'encrypted:array',
        'requested_by' => 'integer',
        'requested_at' => 'datetime',
        'sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    protected $hidden = ['payload'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(MdmDevice::class, 'mdm_device_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_ACKNOWLEDGED, self::STATUS_FAILED], true);
    }
}
