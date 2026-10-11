<?php

namespace App\Livewire\GWater\Concerns;

use App\Models\District;
use App\Models\Permission;
use App\Models\User;
use App\Services\GWater\GwaterVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The Livewire-side wrapper of GwaterVisibility. A separate trait from the Assets, Commercial, Health & Safety and Blog ones
 * on purpose (CLAUDE.md: scoping traits are not shared), and it holds no rule of its own: the rules live in GwaterVisibility,
 * which the services call too. Every G-Water component uses it.
 *
 * Everyone but super_admin works in their own location (taken from their employee record). super_admin has none and picks the
 * district whose books they are in ($locationId); until they do, the screens show only the picker.
 */
trait ScopesGwaterByActor
{
    /** super_admin only: the district whose books are open. Ignored for everyone else. */
    public $locationId = null;

    protected function enforceGwater(): void
    {
        abort_unless(config('gwl.gwater_module_enabled'), 404);
        $this->enforceLivewireModule(Permission::MODULE_GWATER);
    }

    protected function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function visibility(): GwaterVisibility
    {
        return app(GwaterVisibility::class);
    }

    protected function guard(string $permission): void
    {
        abort_unless(Auth::user() && $this->visibility()->can($this->actor(), $permission), 403, 'You do not have permission to do that.');
    }

    protected function isSuperAdmin(): bool
    {
        return $this->visibility()->seesAll($this->actor());
    }

    /** The books this screen is showing: the user's own location, or the one super_admin chose. Null = none yet. */
    protected function workingLocation(): ?array
    {
        if (! $this->isSuperAdmin()) {
            return $this->visibility()->ownLocation($this->actor());
        }

        $district = $this->locationId ? District::query()->find((int) $this->locationId) : null;

        return $district ? ['region_id' => (int) $district->region_id, 'district_id' => (int) $district->id] : null;
    }

    /** A query on any gw_ table, narrowed to the books on screen. Nothing when there are none. */
    protected function books(Builder $query): Builder
    {
        $location = $this->workingLocation();

        if (! $location) {
            return $query->whereRaw('1 = 0');
        }

        // The user's reach first (the rule), then the chosen location for super_admin.
        $query = $this->visibility()->scope($query, $this->actor());

        return $this->isSuperAdmin() ? $this->visibility()->atLocation($query, $location) : $query;
    }

    /** What a write must carry: the district to record into (only super_admin's choice matters). */
    protected function recordingDistrict(): ?int
    {
        return $this->isSuperAdmin() && $this->locationId ? (int) $this->locationId : null;
    }

    /** @return Collection<int, District> for super_admin's picker */
    protected function locationChoices(): Collection
    {
        return $this->isSuperAdmin()
            ? District::query()->with('region')->orderBy('district_name')->get()
            : collect();
    }

    /**
     * Runs a service call, turning its validation errors (keyed by field) into errors on the form array property, which is
     * where the screens show them.
     */
    protected function run(callable $action, string $prefix = 'form'): mixed
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key) => ["{$prefix}.{$key}" => $messages])->all()
            );
        }
    }

    /**
     * The Excel and PDF links of a report for the books on screen, or null when the user may not export (gwater.export AND
     * gwater.view_reports) or there are no books yet. A screen without a period of its own exports the last year.
     *
     * @return array{excel: string, pdf: string}|null
     */
    protected function exportLinks(string $report, ?string $from = null, ?string $to = null): ?array
    {
        $visibility = $this->visibility();

        if (! $this->workingLocation() || ! $visibility->can($this->actor(), 'gwater.export') || ! $visibility->can($this->actor(), 'gwater.view_reports')) {
            return null;
        }

        $query = array_filter([
            'from' => $from ?: today()->subYear()->toDateString(),
            'to' => $to ?: today()->toDateString(),
            'district' => $this->isSuperAdmin() ? $this->locationId : null,
        ]);

        return [
            'excel' => route('gwater.export', ['report' => $report, 'format' => 'excel'] + $query),
            'pdf' => route('gwater.export', ['report' => $report, 'format' => 'pdf'] + $query),
        ];
    }

    /** @return array<string, mixed> what every G-Water view needs to draw the location picker. */
    protected function locationView(): array
    {
        return [
            'location' => $this->workingLocation(),
            'locationChoices' => $this->locationChoices(),
            'isSuperAdmin' => $this->isSuperAdmin(),
        ];
    }
}
