<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\LeaveHrContact;
use App\Models\Region;
use App\Models\User;
use App\Services\Leave\LeaveHrContactService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Who HR is told about finally-approved leave, per scope: Head Office and each region. Head Office HR, admin
 * and super_admin manage every scope; regional HR only their own region (LeaveHrContactService decides).
 */
class HrContacts extends Component
{
    use EnforcesModuleAccess;

    public const HEAD_OFFICE = 'head-office';

    /** The scope being edited: 'head-office' or a region id; empty while adding nothing. */
    public string $scope = '';

    public string $email = '';

    public string $name = '';

    public bool $isActive = true;

    public function mount(LeaveHrContactService $contacts): void
    {
        $this->enforceLivewireModule('leave');

        $user = $this->user();
        $scopes = $contacts->manageableScopes($user);

        abort_unless(
            $contacts->hasPermission($user) && ($scopes['all'] || $scopes['regionIds'] !== []),
            403
        );

        // Regional HR have exactly one scope: start on it.
        if (! $scopes['all']) {
            $this->edit((string) $scopes['regionIds'][0], $contacts);
        }
    }

    public function edit(string $scope, LeaveHrContactService $contacts): void
    {
        $regionId = $this->regionIdFor($scope);
        abort_unless($contacts->canManage($this->user(), $regionId), 403);

        $contact = LeaveHrContact::query()->forScope($regionId)->first();

        $this->resetValidation();
        $this->scope = $scope;
        $this->email = $contact?->email ?? '';
        $this->name = $contact?->name ?? '';
        $this->isActive = $contact?->is_active ?? true;
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->reset('scope', 'email', 'name', 'isActive');
    }

    public function save(LeaveHrContactService $contacts): void
    {
        $validated = $this->validate([
            'scope' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'isActive' => ['boolean'],
        ], [
            'scope.required' => 'Choose Head Office or a region.',
            'email.required' => 'Enter the HR email address.',
        ]);

        $regionId = $this->regionIdFor($validated['scope']);

        // Throws AuthorizationException (a 403) when this user may not manage the scope.
        $contacts->save($this->user(), $regionId, [
            'email' => $validated['email'],
            'name' => $validated['name'] ?: null,
            'is_active' => $validated['isActive'],
        ]);

        $this->dispatch('toast', type: 'success', message: 'HR contact saved.');
        session()->flash('success', 'HR contact saved.');

        $this->cancelEdit();
    }

    public function delete(int $contactId, LeaveHrContactService $contacts): void
    {
        $contacts->delete($this->user(), LeaveHrContact::query()->findOrFail($contactId));

        $this->dispatch('toast', type: 'success', message: 'HR contact removed.');
        session()->flash('success', 'HR contact removed.');

        $this->cancelEdit();
    }

    public function render(LeaveHrContactService $contacts)
    {
        $scopes = $contacts->manageableScopes($this->user());

        $existing = LeaveHrContact::query()->get()->keyBy(fn (LeaveHrContact $c) => $c->region_id ?? self::HEAD_OFFICE);

        $rows = Region::query()
            ->when(! $scopes['all'], fn ($q) => $q->whereIn('id', $scopes['regionIds']))
            ->orderBy('region_name')
            ->get()
            ->map(fn (Region $region) => [
                'scope' => (string) $region->id,
                'label' => $region->region_name,
                'contact' => $existing->get($region->id),
            ]);

        if ($scopes['all']) {
            $rows->prepend([
                'scope' => self::HEAD_OFFICE,
                'label' => 'Head Office',
                'contact' => $existing->get(self::HEAD_OFFICE),
            ]);
        }

        return view('livewire.leave.hr-contacts', [
            'rows' => $rows,
            'editingLabel' => $rows->firstWhere('scope', $this->scope)['label'] ?? null,
        ]);
    }

    protected function user(): User
    {
        return Auth::user();
    }

    /** @return int|null null = Head Office */
    protected function regionIdFor(string $scope): ?int
    {
        if ($scope === self::HEAD_OFFICE) {
            return null;
        }

        abort_unless(ctype_digit($scope) && Region::query()->whereKey((int) $scope)->exists(), 404);

        return (int) $scope;
    }
}
