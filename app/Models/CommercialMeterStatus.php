<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialMeterStatus extends Model
{
    protected $fillable = ['code', 'label', 'is_pending'];

    protected $casts = ['is_pending' => 'boolean'];

    public function display(): string
    {
        return $this->is_pending ? $this->code : $this->label;
    }
}
