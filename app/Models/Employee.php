<?php

namespace App\Models;

use App\Enums\StaffGrade;
use App\Services\Leave\LeaveEntitlementCalculator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasFactory;

    public const RETIREMENT_AGE = 60;

    /** Why an employee was deactivated (employees.deactivation_reason): the options on the deactivation form. */
    public const DEACTIVATION_REASONS = [
        'retired' => 'Retirement',
        'resigned' => 'Resignation',
        'contract_ended' => 'Contract ended',
        'transfer' => 'Transfer',
        'left' => 'Left',
        'dead' => 'Dead',
        'other' => 'Other',
    ];

    /**
     * How the HR analytics groups those reasons. A transfer is an internal move, not someone leaving the company: it is
     * shown in the exit-reasons chart but left out of the exit count and the turnover rate (EXIT_REASON_TRANSFER). The
     * reasons recorded before the list grew (left, dead) and a missing one fall under Other.
     */
    public const EXIT_REASON_GROUPS = [
        'Retirement' => ['retired'],
        'Resignation' => ['resigned'],
        'Contract ended' => ['contract_ended'],
        'Transfer' => ['transfer'],
        'Other' => ['left', 'dead', 'other'],
    ];

    public const EXIT_REASON_TRANSFER = 'transfer';

    protected $fillable = [
        'staff_id',
        'full_name',
        'gender',
        'category',
        'grade',
        'email',
        'job_title_id',
        'district_id',
        'region_id',
        'location_type',
        'date_of_birth',
        'date_joined',
        'present_appointment',
        'department_id',
        'unit',
        'is_active',
        'deactivation_reason',
        'deactivated_at',
    ];

    protected $attributes = [
        'is_active' => true,
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
        'deactivated_at' => 'date',
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

            $employee->location_type = self::locationTypeFor($districtName);

            // The grade fixes the category: it is never stored as something else. Staff with no grade yet keep theirs.
            if ($grade = StaffGrade::tryFrom((string) $employee->grade)) {
                $employee->category = $grade->category();
            }

            // When they left: stamped when the account is deactivated (unless a date was given), cleared on reactivation.
            if ($employee->isDirty('is_active')) {
                $employee->deactivated_at = $employee->is_active ? null : ($employee->deactivated_at ?? today());
            }
        });
    }

    /**
     * An employee's location_type follows the NAME of their district: "Head Office", a "... Regional Office", or
     * anything else is an ordinary district. That is why renaming a district has to re-save its employees
     * (see DistrictEmployeeSync).
     */
    public static function locationTypeFor(?string $districtName): string
    {
        $name = strtolower((string) $districtName);

        return match (true) {
            str_contains($name, 'head office') => 'HeadOffice',
            str_contains($name, 'regional office') => 'Region',
            default => 'District',
        };
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

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'requester_id');
    }

    public function getAgeAttribute(): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        return Carbon::parse($this->date_of_birth)->age;
    }

    public function getRetirementDateAttribute(): ?Carbon
    {
        return self::retirementDateFromBirthDate($this->date_of_birth);
    }

    /** The age staff retire at: config gwl.retirement_age (default RETIREMENT_AGE, 60). */
    public static function retirementAge(): int
    {
        return (int) config('gwl.retirement_age', self::RETIREMENT_AGE);
    }

    public static function retirementDateFromBirthDate(mixed $dateOfBirth): ?Carbon
    {
        if (! $dateOfBirth) {
            return null;
        }

        return Carbon::parse($dateOfBirth)->addYearsNoOverflow(self::retirementAge());
    }

    /**
     * Annual days available this year: the grade's entitlement less compulsory leave (LeaveEntitlementCalculator).
     * Worked out, not read from leave_entitlements, so listing employees costs no query per row.
     */
    public function getAnnualLeaveDaysAttribute(): int
    {
        return app(LeaveEntitlementCalculator::class)->netEntitlement($this, (int) now()->format('Y'));
    }

    /** The StaffGrade this employee holds, or null (not graded yet, or a value that is no longer a grade). */
    public function staffGrade(): ?StaffGrade
    {
        return StaffGrade::tryFrom((string) $this->grade);
    }

    /** Contract staff have no leave: see LeaveEntitlementCalculator::isEligible(). */
    public function isLeaveEligible(): bool
    {
        return app(LeaveEntitlementCalculator::class)->isEligible($this);
    }

    public function getCasualLeaveDaysAttribute(): int
    {
        return 5;
    }

    public function getParentalDaysAttribute(): int
    {
        return $this->gender === 'Female' ? 93 : 7;
    }

    public function getInitialsAttribute(): string
    {
        return collect(preg_split('/\s+/', trim($this->full_name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => strtoupper(substr($part, 0, 1)))
            ->join('') ?: 'NA';
    }

    public function getDeactivationReasonLabelAttribute(): ?string
    {
        return self::DEACTIVATION_REASONS[$this->deactivation_reason] ?? null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVisibleInErp($query)
    {
        return $query->whereDoesntHave('userByStaffId.roles', fn ($roleQuery) => $roleQuery->where('name', 'super_admin'));
    }

    /** Employees $viewer may see in the UAC and staff contexts (see User::scopeVisibleTo()). */
    public function scopeVisibleTo($query, ?User $viewer)
    {
        return $viewer?->isSuperAdmin() ? $query : $query->visibleInErp();
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

    // Leave approval hierarchy helpers
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
        if (! $this->unit) {
            return null;
        }

        return self::query()
            ->where('department_id', $this->department_id)
            ->where('unit', $this->unit)
            ->whereHas('userByStaffId.roles', fn ($q) => $q->where('name', 'manager'))
            ->first();
    }

    public function departmentManager(): ?self
    {
        if (! $this->department_id) {
            return null;
        }

        return self::query()
            ->where('department_id', $this->department_id)
            ->whereHas('userByStaffId.roles', fn ($q) => $q->where('name', 'departmental_manager'))
            ->first();
    }

    public function districtManager(): ?self
    {
        if (! $this->district_id) {
            return null;
        }

        return self::query()
            ->where('district_id', $this->district_id)
            ->whereHas('userByStaffId.roles', fn ($q) => $q->where('name', 'district_manager'))
            ->first();
    }

    public function chiefManager(): ?self
    {
        if ($this->isHeadOffice()) {
            return self::query()
                ->where('department_id', $this->department_id)
                ->whereHas('userByStaffId.roles', fn ($q) => $q->where('name', 'chief_manager'))
                ->first();
        }

        if ($this->isRegion() || $this->isDistrict()) {
            return self::query()
                ->where('region_id', $this->region_id)
                ->whereHas('userByStaffId.roles', fn ($q) => $q->where('name', 'regional_chief_manager'))
                ->first();
        }

        return null;
    }
}
