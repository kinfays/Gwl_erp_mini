<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\LeaveLetterhead;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\Leave\LeaveLetterSettingsService;
use App\Services\Leave\SignatureService;
use Livewire\Component;

/**
 * Letter Settings: the letterhead of each location (regional HR: their own region only; Head Office HR, Global Admin and
 * super_admin: every region and Head Office) and the company block printed in every letter's footer (Head Office HR, Global
 * Admin and super_admin only). Global Admin and super_admin can also revoke a signature here, never see or add one.
 */
class LetterSettings extends Component
{
    use EnforcesModuleAccess;

    public const HEAD_OFFICE = 'head-office';

    /** The location being edited: HEAD_OFFICE or a region id. */
    public string $scope = self::HEAD_OFFICE;

    public string $regionName = '';

    public string $addressLines = '';

    public string $hrSignatory = '';

    public string $defaultCc = '';

    public string $bankers = '';

    /** @var array<int, array{name: string, role: string}> */
    public array $board = [];

    public string $registeredOffice = '';

    public string $telephone = '';

    public string $website = '';

    public string $email = '';

    public function mount(LeaveLetterSettingsService $settings): void
    {
        $this->enforceLivewireModule('leave');
        abort_unless($settings->canAccess($this->user()), 403);

        $first = $settings->letterheadsFor($this->user())->first();
        abort_unless($first, 403, 'Your profile needs a region before you can edit its letterhead.');

        $this->scope = $this->keyOf($first);
        $this->loadLetterhead($settings);
        $this->loadCompany($settings);
    }

    public function updatedScope(LeaveLetterSettingsService $settings): void
    {
        $this->resetValidation();
        $this->loadLetterhead($settings);
    }

    public function saveLetterhead(LeaveLetterSettingsService $settings): void
    {
        $settings->saveLetterhead($this->user(), $this->regionId(), [
            'region_name' => $this->regionName,
            'address_lines' => $settings->lines($this->addressLines),
            'hr_signatory_user_id' => $this->hrSignatory !== '' ? (int) $this->hrSignatory : null,
            'default_cc' => $settings->lines($this->defaultCc),
        ]);

        $this->loadLetterhead($settings);
        $this->notify('Letterhead saved.');
    }

    public function saveCompany(LeaveLetterSettingsService $settings): void
    {
        $settings->saveCompany($this->user(), [
            'bankers' => $settings->lines($this->bankers),
            'board_members' => $this->board,
            'registered_office' => $this->registeredOffice,
            'telephone' => $this->telephone,
            'website' => $this->website,
            'email' => $this->email,
        ]);

        $this->loadCompany($settings);
        $this->notify('Company details saved.');
    }

    public function addDirector(): void
    {
        $this->board[] = ['name' => '', 'role' => 'Member'];
    }

    public function removeDirector(int $index): void
    {
        unset($this->board[$index]);
        $this->board = array_values($this->board);
    }

    /** Global Admin and super_admin only (the service refuses anyone else). */
    public function revokeSignature(int $userId, SignatureService $signatures): void
    {
        $signatures->revoke($this->user(), User::query()->findOrFail($userId));

        $this->notify('The signature was revoked.');
    }

    public function render(LeaveLetterSettingsService $settings, SignatureService $signatures)
    {
        $user = $this->user();
        $letterheads = $settings->letterheadsFor($user);

        return view('livewire.leave.letter-settings', [
            'letterheads' => $letterheads,
            'current' => $letterheads->first(fn (LeaveLetterhead $row) => $this->keyOf($row) === $this->scope),
            'candidates' => $settings->signatoryCandidates($this->regionId()),
            'canEditCompany' => $settings->canEditCompany($user),
            'boardRoles' => LeaveLetterSettingsService::BOARD_ROLES,
            // Who has a signature on file (never the signature itself), for the people who can revoke one.
            'signatureHolders' => $signatures->canRevokeOthers($user)
                ? UserSignature::query()->whereNull('revoked_at')->where('is_active', true)->with('user')->latest('id')->get()
                : collect(),
            'keyOf' => fn (LeaveLetterhead $row) => $this->keyOf($row),
        ]);
    }

    protected function loadLetterhead(LeaveLetterSettingsService $settings): void
    {
        $letterhead = $settings->letterheadFor($this->regionId());

        $this->regionName = $letterhead->region_name;
        $this->addressLines = implode("\n", (array) $letterhead->address_lines);
        $this->hrSignatory = (string) ($letterhead->hr_signatory_user_id ?? '');
        $this->defaultCc = implode("\n", (array) $letterhead->default_cc);
    }

    protected function loadCompany(LeaveLetterSettingsService $settings): void
    {
        $company = $settings->company();

        $this->bankers = implode("\n", $company['bankers']);
        $this->board = array_map(fn ($member) => ['name' => $member['name'], 'role' => $member['role'] ?? 'Member'], $company['board']);
        $this->registeredOffice = (string) $company['registered_office'];
        $this->telephone = (string) $company['telephone'];
        $this->website = (string) $company['website'];
        $this->email = (string) $company['email'];
    }

    protected function keyOf(LeaveLetterhead $letterhead): string
    {
        return $letterhead->region_id === null ? self::HEAD_OFFICE : (string) $letterhead->region_id;
    }

    protected function regionId(): ?int
    {
        return $this->scope === self::HEAD_OFFICE ? null : (int) $this->scope;
    }

    protected function notify(string $message): void
    {
        session()->flash('success', $message);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    protected function user(): User
    {
        $user = auth()->user();

        abort_unless($user, 403);

        return $user;
    }
}
