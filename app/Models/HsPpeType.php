<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of PPE (safety boots, hard hat...). Types are deactivated, never deleted. No type ships with the system: the names,
 * sizes and service lives are for EHS to decide.
 */
class HsPpeType extends Model
{
    public const CATEGORIES = [
        'head' => 'Head',
        'eye_face' => 'Eye and face',
        'hearing' => 'Hearing',
        'respiratory' => 'Respiratory',
        'hand' => 'Hand',
        'foot' => 'Foot',
        'body' => 'Body and hi-vis',
        'fall_water' => 'Fall and water',
    ];

    /**
     * Offered by "Load suggested types": a name, a category and whether it comes in sizes, and nothing else (no sizes, no
     * service life: those are for EHS to set). They only fill an editable list; nothing is saved until an officer saves it.
     * [name, category, has sizes].
     */
    public const SUGGESTED = [
        ['Safety helmet', 'head', false],
        ['Safety glasses', 'eye_face', false],
        ['Ear plugs', 'hearing', false],
        ['Ear muffs', 'hearing', false],
        ['Dust mask', 'respiratory', false],
        ['Work gloves', 'hand', true],
        ['Safety boots', 'foot', true],
        ['Gumboots', 'foot', true],
        ['Reflective vest', 'body', true],
        ['Coveralls', 'body', true],
        ['Rain coat', 'body', true],
        ['Life jacket', 'fall_water', true],
        ['Safety harness', 'fall_water', true],
    ];

    protected $fillable = [
        'name',
        'category',
        'has_sizes',
        'sizes',
        'replacement_months',
        'has_expiry',
        'unit',
        'is_active',
    ];

    protected $casts = [
        'has_sizes' => 'boolean',
        'sizes' => 'array',
        'replacement_months' => 'integer',
        'has_expiry' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(HsPpeStockMovement::class, 'ppe_type_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(HsPpeIssue::class, 'ppe_type_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(HsPpeEntitlement::class, 'ppe_type_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /** @return list<string> */
    public function sizeList(): array
    {
        return $this->has_sizes ? array_values($this->sizes ?? []) : [];
    }

    /** Used by any record: its sizes and "has sizes" setting are then fixed. */
    public function isInUse(): bool
    {
        return $this->movements()->exists() || $this->issues()->exists();
    }
}
