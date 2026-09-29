<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MdmEvent extends Model
{
    use HasFactory;

    public const MODE_PUSH = 'push';

    public const MODE_PULL = 'pull';

    public $timestamps = false;

    protected $table = 'mdm_events';

    protected $fillable = [
        'pubsub_message_id',
        'delivery_mode',
        'notification_type',
        'google_device_name',
        'payload',
        'received_at',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
