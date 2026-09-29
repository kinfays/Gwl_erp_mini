<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdmPolicy extends Model
{
    use HasFactory;

    public const PLAY_STORE_MODES = [
        'WHITELIST' => 'Allow-list — only apps in this policy; everything else is removed',
        'BLACKLIST' => 'Block-list — all apps available except those marked Blocked',
    ];

    public const CAMERA_ACCESS = [
        'CAMERA_ACCESS_USER_CHOICE' => 'Available (user can use the camera toggle)',
        'CAMERA_ACCESS_ENFORCED' => 'Available (user cannot switch it off)',
        'CAMERA_ACCESS_DISABLED' => 'Disabled on the whole device',
    ];

    public const USB_DATA_ACCESS = [
        'DISALLOW_USB_FILE_TRANSFER' => 'Block file transfer (keyboards / mice still work)',
        'DISALLOW_USB_DATA_TRANSFER' => 'Block all USB data (Android 12+)',
        'ALLOW_USB_DATA_TRANSFER' => 'Allow all USB data transfer',
    ];

    public const UNTRUSTED_APPS = [
        'DISALLOW_INSTALL' => 'Disallow apps from unknown sources',
        'ALLOW_INSTALL_DEVICE_WIDE' => 'Allow apps from unknown sources',
    ];

    public const DEVELOPER_SETTINGS = [
        'DEVELOPER_SETTINGS_DISABLED' => 'Disabled (developer options and safe boot)',
        'DEVELOPER_SETTINGS_ALLOWED' => 'Allowed',
    ];

    public const PASSWORD_QUALITIES = [
        '' => 'No requirement',
        'NUMERIC' => 'Numeric PIN',
        'NUMERIC_COMPLEX' => 'Numeric PIN, no repeating or ordered digits',
        'ALPHABETIC' => 'Letters',
        'ALPHANUMERIC' => 'Letters and digits',
        'COMPLEX' => 'Complex',
    ];

    public const SYSTEM_UPDATE_TYPES = [
        'AUTOMATIC' => 'Install automatically as soon as available',
        'WINDOWED' => 'Install automatically inside a daily window',
        'POSTPONE' => 'Postpone for up to 30 days',
    ];

    protected $table = 'mdm_policies';

    protected $fillable = [
        'name',
        'description',
        'google_policy_name',
        'play_store_mode',
        'install_apps_disabled',
        'uninstall_apps_disabled',
        'factory_reset_disabled',
        'add_user_disabled',
        'screen_capture_disabled',
        'camera_access',
        'usb_data_access',
        'untrusted_apps_policy',
        'developer_settings',
        'password_min_length',
        'password_quality',
        'system_update_type',
        'system_update_start_minutes',
        'system_update_end_minutes',
        'frp_admin_emails',
        'last_published_payload',
        'published_at',
        'published_by',
        'version',
        'created_by',
    ];

    protected $casts = [
        'install_apps_disabled' => 'boolean',
        'uninstall_apps_disabled' => 'boolean',
        'factory_reset_disabled' => 'boolean',
        'add_user_disabled' => 'boolean',
        'screen_capture_disabled' => 'boolean',
        'password_min_length' => 'integer',
        'system_update_start_minutes' => 'integer',
        'system_update_end_minutes' => 'integer',
        'frp_admin_emails' => 'array',
        'last_published_payload' => 'array',
        'published_at' => 'datetime',
        'version' => 'integer',
    ];

    public function apps(): HasMany
    {
        return $this->hasMany(MdmPolicyApp::class)->orderBy('package_name');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(MdmDevice::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->google_policy_name !== null && $this->published_at !== null;
    }
}
