<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An account status code. Where the meaning is not confirmed the raw code is what the screens show. */
class CommercialCustomerStatus extends Model
{
    protected $fillable = ['code', 'label', 'is_active', 'is_billing', 'meaning_confirmed', 'is_pending'];

    protected $casts = ['is_active' => 'boolean', 'is_billing' => 'boolean', 'meaning_confirmed' => 'boolean', 'is_pending' => 'boolean'];

    /** What to show: the label when its meaning is confirmed, otherwise the raw code (never a guess). */
    public function display(): string
    {
        return $this->meaning_confirmed ? $this->label.' ('.$this->code.')' : $this->code;
    }
}
