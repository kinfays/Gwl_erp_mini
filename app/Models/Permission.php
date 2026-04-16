<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    public const MODULE_UAC = 'uac';
    public const MODULE_LEAVE = 'leave';
    public const MODULE_STAFF = 'staff';
    public const MODULE_LETTERS = 'letters';
    public const MODULE_VISITORS = 'visitors';

    public const MODULES = [
        self::MODULE_UAC,
        self::MODULE_LEAVE,
        self::MODULE_STAFF,
        self::MODULE_LETTERS,
        self::MODULE_VISITORS,
    ];

    protected $fillable = [
        'name',
        'display_name',
        'module',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}