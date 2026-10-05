<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\LeaveLetterhead;
use App\Models\LeaveLetterSetting;
use App\Models\Region;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What is printed around a leave approval letter and who may change it.
 *
 *  Letterhead (per location: a region, or Head Office as the null region): the address block on the right and the HR person
 *    who signs "for" the chief manager. Regional HR may edit only their own region; Head Office HR, Global Admin and
 *    super_admin may edit any region and Head Office.
 *  Company block (bankers, the Board of Directors, registered office, telephone, website, e-mail, printed in every letter's
 *    footer): Head Office HR, Global Admin and super_admin only.
 *
 * Letters already issued keep the copy they were generated with (LeaveLetterService snapshots both), so every edit here
 * only affects letters generated afterwards. Every save is audited with the old and new values.
 */
class LeaveLetterSettingsService
{
    public const PERMISSION = 'leave.manage_letter_settings';

    public const BOARD_ROLES = ['Chairman', 'Managing Director', 'Member'];

    public function __construct(protected LeaveHrContactService $hrScopes) {}

    // ------------------------------------------------------------------ access

    /** May $user open Letter Settings at all? */
    public function canAccess(User $user): bool
    {
        if ($user->hasRoles('super_admin', 'admin')) {
            return true;
        }

        return $user->hasRoles('hr_headoffice', 'hr_region') && $user->hasPermission(self::PERMISSION);
    }

    /** The company block and the board: not regional HR. */
    public function canEditCompany(User $user): bool
    {
        return $user->hasRoles('super_admin', 'admin')
            || ($user->hasRoles('hr_headoffice') && $user->hasPermission(self::PERMISSION));
    }

    public function canEditScope(User $user, ?int $regionId): bool
    {
        if (! $this->canAccess($user)) {
            return false;
        }

        $scopes = $this->hrScopes->manageableScopes($user);

        return $scopes['all'] || ($regionId !== null && in_array($regionId, $scopes['regionIds'], true));
    }

    // ------------------------------------------------------------------ reading

    /** The letterhead of a location, created (empty, flagged "address not set") the first time it is needed. */
    public function letterheadFor(?int $regionId): LeaveLetterhead
    {
        $existing = LeaveLetterhead::query()->when($regionId === null, fn ($q) => $q->whereNull('region_id'), fn ($q) => $q->where('region_id', $regionId))->first();

        if ($existing) {
            return $existing;
        }

        return LeaveLetterhead::query()->create([
            'region_id' => $regionId,
            'region_name' => $regionId === null ? 'Head Office' : (Region::query()->whereKey($regionId)->value('region_name') ?? 'Region'),
            'address_lines' => [],
            'default_cc' => [],
        ]);
    }

    /**
     * The letterheads $user may edit: Head Office and every region, or just their own region.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, LeaveLetterhead>
     */
    public function letterheadsFor(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $scopes = $this->hrScopes->manageableScopes($user);

        if (! $this->canAccess($user)) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $rows = collect();

        if ($scopes['all']) {
            $rows->push($this->letterheadFor(null));
            Region::query()->orderBy('region_name')->pluck('id')->each(fn (int $id) => $rows->push($this->letterheadFor($id)));
        } else {
            foreach ($scopes['regionIds'] as $id) {
                $rows->push($this->letterheadFor($id));
            }
        }

        return (new \Illuminate\Database\Eloquent\Collection($rows->all()))->load('hrSignatory');
    }

    /** The company block as the arrays the letter prints. @return array<string, mixed> */
    public function company(): array
    {
        $company = LeaveLetterSetting::current();

        return [
            'bankers' => array_values(array_filter((array) $company->bankers, fn ($line) => filled($line))),
            'board' => array_values(array_filter((array) $company->board_members, fn ($member) => filled($member['name'] ?? null))),
            'registered_office' => $company->registered_office,
            'telephone' => $company->telephone,
            'website' => $company->website,
            'email' => $company->email,
        ];
    }

    /**
     * Users who can be the HR signatory of a location: Head Office HR for any, regional HR of that region for a region.
     *
     * @return Collection<int, User>
     */
    public function signatoryCandidates(?int $regionId): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($regionId) {
                $query->whereHas('roles', fn ($roles) => $roles->where('name', 'hr_headoffice'));

                if ($regionId !== null) {
                    $query->orWhere(fn ($regional) => $regional
                        ->whereHas('roles', fn ($roles) => $roles->where('name', 'hr_region'))
                        ->where(fn ($q) => $q
                            ->whereHas('employee', fn ($e) => $e->where('region_id', $regionId))
                            ->orWhereHas('employeeByStaffId', fn ($e) => $e->where('region_id', $regionId))));
                }
            })
            ->with(['employee', 'employeeByStaffId'])
            ->orderBy('full_name')
            ->get();
    }

    // ------------------------------------------------------------------ writing

    /**
     * @param  array{region_name?: string, address_lines?: array<int, string>, hr_signatory_user_id?: int|string|null, default_cc?: array<int, string>}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function saveLetterhead(User $actor, ?int $regionId, array $data): LeaveLetterhead
    {
        if (! $this->canEditScope($actor, $regionId)) {
            throw new AuthorizationException('You are not allowed to edit this letterhead.');
        }

        $letterhead = $this->letterheadFor($regionId);
        $signatory = ($data['hr_signatory_user_id'] ?? null) ?: null;

        if ($signatory !== null && ! $this->signatoryCandidates($regionId)->contains('id', (int) $signatory)) {
            throw ValidationException::withMessages(['hr_signatory_user_id' => 'Choose an active HR user for this location.']);
        }

        $old = $this->snapshot($letterhead);

        $letterhead->update([
            'region_name' => filled($data['region_name'] ?? null) ? trim($data['region_name']) : $letterhead->region_name,
            'address_lines' => $this->lines($data['address_lines'] ?? []),
            'hr_signatory_user_id' => $signatory,
            'default_cc' => $this->lines($data['default_cc'] ?? []),
            'updated_by' => $actor->id,
        ]);

        AuditLog::record('leave_letterhead_updated', 'leave', 'leave_letterheads', $letterhead->id, $old, $this->snapshot($letterhead->fresh()), ['region_id' => $regionId]);

        return $letterhead->fresh();
    }

    /**
     * @param  array{bankers?: array<int, string>, board_members?: array<int, array{name?: string, role?: string}>, registered_office?: ?string, telephone?: ?string, website?: ?string, email?: ?string}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function saveCompany(User $actor, array $data): LeaveLetterSetting
    {
        if (! $this->canEditCompany($actor)) {
            throw new AuthorizationException('You are not allowed to edit the board and company details.');
        }

        $board = collect($data['board_members'] ?? [])
            ->map(fn ($member) => ['name' => trim((string) ($member['name'] ?? '')), 'role' => (string) ($member['role'] ?? 'Member')])
            ->filter(fn (array $member) => $member['name'] !== '')
            ->values();

        if ($board->contains(fn (array $member) => ! in_array($member['role'], self::BOARD_ROLES, true))) {
            throw ValidationException::withMessages(['board_members' => 'Each director is Chairman, Managing Director or Member.']);
        }

        foreach (['Chairman', 'Managing Director'] as $single) {
            if ($board->where('role', $single)->count() > 1) {
                throw ValidationException::withMessages(['board_members' => "There can be only one {$single}."]);
            }
        }

        $company = LeaveLetterSetting::current();
        $old = $this->companySnapshot($company);

        $company->update([
            'bankers' => $this->lines($data['bankers'] ?? []),
            'board_members' => $board->all(),
            'registered_office' => $this->text($data['registered_office'] ?? null),
            'telephone' => $this->text($data['telephone'] ?? null),
            'website' => $this->text($data['website'] ?? null),
            'email' => $this->text($data['email'] ?? null),
            'updated_by' => $actor->id,
        ]);

        AuditLog::record('leave_letter_company_updated', 'leave', 'leave_letter_settings', $company->id, $old, $this->companySnapshot($company->fresh()));

        return $company->fresh();
    }

    // ------------------------------------------------------------------ helpers

    /** @param  array<int, mixed>|string  $lines  @return list<string> */
    public function lines(array|string $lines): array
    {
        $lines = is_string($lines) ? preg_split('/\R/', $lines) : $lines;

        return collect($lines)->map(fn ($line) => trim((string) $line))->filter()->values()->all();
    }

    protected function text(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }

    /** @return array<string, mixed> */
    protected function snapshot(LeaveLetterhead $letterhead): array
    {
        return $letterhead->only(['region_name', 'address_lines', 'hr_signatory_user_id', 'default_cc']);
    }

    /** @return array<string, mixed> */
    protected function companySnapshot(LeaveLetterSetting $company): array
    {
        return $company->only(['bankers', 'board_members', 'registered_office', 'telephone', 'website', 'email']);
    }
}
