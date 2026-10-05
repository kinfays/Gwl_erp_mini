<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The one company record printed on every approval letter: bankers, the board and the footer details. */
class LeaveLetterSetting extends Model
{
    protected $fillable = ['bankers', 'board_members', 'registered_office', 'telephone', 'website', 'email', 'updated_by'];

    protected $casts = [
        'bankers' => 'array',
        'board_members' => 'array',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create(['bankers' => [], 'board_members' => []]);
    }
}
