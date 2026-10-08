<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a new kit of a type starts with. A kit COPIES these when it is created, so editing a template later never changes
 * a kit that already exists. No rows ship with the system: the contents are for EHS or a first aid trainer to confirm.
 */
class HsFirstAidItemTemplate extends Model
{
    /**
     * Generic items offered by "Load suggested starter items". They only fill an editable list on the Kit templates
     * screen; nothing is saved until an officer saves it, and the contents must be confirmed with EHS or a first aid
     * trainer first. [name, quantity, has an expiry date].
     */
    public const STARTER_ITEMS = [
        ['Adhesive plasters, assorted', 20, true],
        ['Sterile gauze dressings', 6, true],
        ['Triangular bandage', 2, false],
        ['Crepe / conforming bandage', 2, false],
        ['Medical tape', 1, true],
        ['Burn dressing', 2, true],
        ['Eye wash / sterile saline', 1, true],
        ['Antiseptic wipes', 10, true],
        ['Disposable gloves (pair)', 4, false],
        ['CPR face shield', 1, false],
        ['Scissors', 1, false],
        ['Safety pins', 6, false],
    ];

    protected $fillable = [
        'kit_type',
        'item_name',
        'required_qty',
        'has_expiry',
        'sort_order',
    ];

    protected $casts = [
        'required_qty' => 'integer',
        'has_expiry' => 'boolean',
        'sort_order' => 'integer',
    ];
}
