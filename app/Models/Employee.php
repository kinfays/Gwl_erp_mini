<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'full_name',
        'gender',
        'category',
        'email',
        'job_title_id',
        'district_id',
        'region_id',
        'location_type',
        'date_of_birth',
        'date_joined',
        'present_appointment',
        'role',
        'department_id',
        'unit',
        'is_active',
    ];

    protected $appends = [
        'age',
        'annual_leave_days',
        'casual_leave_days',
        'parental_days',
    ];

    protected $casts = [
        'job_title_id' => 'integer',
        'district_id' => 'integer',
        'region_id' => 'integer',
        'department_id' => 'integer',
        'date_of_birth' => 'date',
        'date_joined' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Employee $employee) {
            $districtName = $employee->district?->district_name;

            if (! $districtName && $employee->district_id) {
                $districtName = District::query()->whereKey($employee->district_id)->value('district_name');
            }

            $districtName = strtolower((string) $districtName);

            if (str_contains($districtName, 'head office')) {
                $employee->location_type = 'HeadOffice';
            } elseif (str_contains($districtName, 'regional office')) {
                $employee->location_type = 'Region';
            } else {
                $employee->location_type = 'District';
            }
        });
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function userByStaffId(): HasOne
    {
        return $this->hasOne(User::class, 'staff_id', 'staff_id');
    }

    public function getAgeAttribute(): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        return Carbon::parse($this->date_of_birth)->age;
    }

    public function getAnnualLeaveDaysAttribute(): int
    {
        return 31;
    }

    public function getCasualLeaveDaysAttribute(): int
    {
        return 5;
    }

    public function getParentalDaysAttribute(): int
    {
        return $this->gender === 'Female' ? 93 : 7;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAtLocation($query, string $locationType)
    {
        return $query->where('location_type', $locationType);
    }

    public function scopeInRegion($query, int $regionId)
    {
        return $query->where('region_id', $regionId);
    }

    public function scopeInDistrict($query, int $districtId)
    {
        return $query->where('district_id', $districtId);
    }

    public function isHeadOffice(): bool
{
    return is_null($this->region_id) && is_null($this->district_id);
}


//leave approval hierarchy helpers
public function isRegion(): bool
{
    return ! is_null($this->region_id) && is_null($this->district_id);
}

public function isDistrict(): bool
{
    return ! is_null($this->district_id);
}

public function unitManager(): ?self
{
    if (! $this->unit_id) {
        return null;
    }

    return self::where('unit_id', $this->unit_id)
        ->whereHas('roles', fn ($q) =>
            $q->where('name', 'Unit Manager')
        )
        ->first();
}

public function departmentManager(): ?self
{
    if (! $this->department_id) {
        return null;
    }

    return self::where('department_id', $this->department_id)
        ->whereHas('roles', fn ($q) =>
            $q->whereIn('name', [
                'Department Manager',
                'Dept Manager',
            ])
        )
        ->first();
}

public function districtManager(): ?self
{
    if (! $this->district_id) {
        return null;
    }

    return self::where('district_id', $this->district_id)
        ->whereHas('roles', fn ($q) =>
            $q->where('name', 'District Manager')
        )
        ->first();
}

public function chiefManager(): ?self
{
    // Head Office Chief Manager
    if ($this->isHeadOffice()) {
        return self::whereHas('roles', fn ($q) =>
            $q->where('name', 'Chief Manager')
        )->whereNull('region_id')->first();
    }

    // Regional Chief Manager
    if ($this->isRegion() || $this->isDistrict()) {
        return self::where('region_id', $this->region_id)
            ->whereHas('roles', fn ($q) =>
                $q->where('name', 'Regional Chief Manager')
            )
            ->first();
    }

    return null;
}

}