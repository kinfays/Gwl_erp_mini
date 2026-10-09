<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Personal data of a customer (name, address, phones, e-mail), kept apart from the hot table. Never log or audit its values. */
class CommercialCustomerContact extends Model
{
    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['account_name', 'address', 'mobiles', 'phone_primary', 'email', 'email_lower', 'name_search'];
}
