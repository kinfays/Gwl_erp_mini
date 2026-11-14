<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Models\Permission;
use App\Services\Commercial\RouteResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Commercial > Route master (design 8.30, analysis X10): every route with its district, where it was first seen, whether it is the
 * canonical route or an alias, and how many customers the customer list files under it; a filter for the conflicts (one code under
 * several districts, the unassigned 0000); and the two things a Commercial Manager may do by hand: pin a route to a district, mark
 * a route as the alias of another. The rules are RouteResolver's; this screen only asks it, inside the viewer's region.
 */
class Routes extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;
    use WithPagination;

    public const FILTERS = ['all' => 'All routes', 'conflicts' => 'Same code under several districts', 'unassigned' => 'Unassigned (0000)', 'pending' => 'Pending (not confirmed)', 'aliases' => 'Aliases'];

    #[Url]
    public string $filter = 'all';

    #[Url]
    public string $search = '';

    #[Url]
    public string $region = '';

    /** The route being pinned or aliased, and what it is being given. */
    public ?int $editing = null;

    public string $pinDistrictId = '';

    public string $aliasOfId = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.manage_routes');
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRegion(): void
    {
        $this->resetPage();
    }

    /** @return list<int>|null the regions the viewer may see (null = all) */
    protected function regionIds(): ?array
    {
        return $this->commercialScope()->regions;
    }

    protected function scoped(): Builder
    {
        $regions = $this->regionIds();
        $query = CommercialRoute::query();

        return $regions === null ? $query : ($regions === [] ? $query->whereRaw('1 = 0') : $query->whereIn('region_id', $regions));
    }

    /** One route inside the viewer's region, or 404 (another region's route is never confirmed to exist). */
    protected function route(int $id): CommercialRoute
    {
        return $this->scoped()->whereKey($id)->firstOrFail();
    }

    public function startPin(int $id): void
    {
        $this->guardCommercialPermission('commercial.manage_routes');
        $this->editing = $this->route($id)->id;
        $this->pinDistrictId = '';
        $this->aliasOfId = '';
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function pin(RouteResolver $resolver): void
    {
        $this->guardCommercialPermission('commercial.manage_routes');
        $route = $this->route((int) $this->editing);
        $district = District::query()->whereKey((int) $this->pinDistrictId)->first();

        // only a district of a region the viewer may see
        if (! $district || ! $this->commercialScope()->seesRegion((int) $district->region_id)) {
            $this->addError('pinDistrictId', 'Choose a district of your region.');

            return;
        }

        try {
            $resolver->pin($route->id, $district->id, auth()->id());
        } catch (ValidationException $e) {
            $this->addError('pinDistrictId', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->cancel();
        $this->dispatch('toast', type: 'success', message: 'The route is pinned to '.$district->district_name.'.');
    }

    public function markAlias(RouteResolver $resolver): void
    {
        $this->guardCommercialPermission('commercial.manage_routes');
        $route = $this->route((int) $this->editing);
        $canonical = $this->scoped()->whereKey((int) $this->aliasOfId)->first();

        if (! $canonical) {
            $this->addError('aliasOfId', 'Choose the route this one is an alias of.');

            return;
        }

        try {
            $resolver->markAlias($route->id, $canonical->id);
        } catch (ValidationException $e) {
            $this->addError('aliasOfId', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->cancel();
        $this->dispatch('toast', type: 'success', message: 'The route is now an alias.');
    }

    public function release(int $id, RouteResolver $resolver): void
    {
        $this->guardCommercialPermission('commercial.manage_routes');
        $resolver->release($this->route($id)->id);
        $this->dispatch('toast', type: 'success', message: 'The setting decides this route again.');
    }

    public function confirm(int $id, RouteResolver $resolver): void
    {
        $this->guardCommercialPermission('commercial.manage_routes');
        $resolver->confirm($this->route($id)->id);
    }

    public function rebuild(RouteResolver $resolver): void
    {
        $this->guardCommercialPermission('commercial.manage_routes');
        $count = $resolver->rebuild();
        $this->dispatch('toast', type: 'success', message: $count === 0 ? 'No code is shared by several routes.' : $count.' shared code(s) settled under the current setting.');
    }

    public function render(RouteResolver $resolver)
    {
        $this->guardCommercialPermission('commercial.manage_routes');

        $filter = array_key_exists($this->filter, self::FILTERS) ? $this->filter : 'all';
        $conflicts = $resolver->conflicts($this->regionIds());
        $query = $this->scoped()->with(['district:id,district_name', 'region:id,region_name', 'canonical.district:id,district_name'])
            ->when(ctype_digit($this->region) && $this->region !== '', fn (Builder $q) => $q->where('region_id', (int) $this->region))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('code', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%')->orWhere('name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->search)).'%')));

        match ($filter) {
            'conflicts' => $query->where(function (Builder $q) use ($conflicts) {
                $conflicts['conflicts'] === [] ? $q->whereRaw('1 = 0') : collect($conflicts['conflicts'])->each(fn (array $c) => $q->orWhere(fn (Builder $one) => $one->where('region_id', $c['region_id'])->where('code', $c['code'])));
            }),
            'unassigned' => $query->where('code', CommercialRoute::UNASSIGNED_CODE),
            'pending' => $query->where('is_pending', true),
            'aliases' => $query->whereNotNull('canonical_route_id'),
            default => null,
        };

        $routes = $query->orderBy('region_id')->orderBy('code')->orderBy('district_id')->paginate(25);

        // customers filed under each route of the page (one grouped read, by the route index)
        $customers = $routes->isEmpty() ? collect() : DB::table('commercial_customers')->whereIn('route_id', $routes->pluck('id')->all())->whereNull('missing_since_batch_id')
            ->selectRaw('route_id, COUNT(*) AS n')->groupBy('route_id')->pluck('n', 'route_id');

        $editingRoute = $this->editing ? $this->scoped()->with('district:id,district_name')->find($this->editing) : null;
        $regions = $this->regionIds();

        return view('livewire.commercial.routes', [
            'routes' => $routes,
            'customers' => $customers,
            'filters' => self::FILTERS,
            'activeFilter' => $filter,
            'conflicts' => $conflicts,
            'authority' => $resolver->authority(),
            'editingRoute' => $editingRoute,
            'districts' => $editingRoute
                ? District::query()->when($regions !== null, fn (Builder $q) => $regions === [] ? $q->whereRaw('1 = 0') : $q->whereIn('region_id', $regions))->where('region_id', $editingRoute->region_id ?? 0)->orderBy('district_name')->get(['id', 'district_name'])
                : collect(),
            'sameCode' => $editingRoute && $editingRoute->code !== null
                ? $this->scoped()->where('region_id', $editingRoute->region_id)->where('code', $editingRoute->code)->whereKeyNot($editingRoute->id)->with('district:id,district_name')->get()
                : collect(),
            'regionOptions' => \App\Models\Region::query()->when($regions !== null, fn (Builder $q) => $regions === [] ? $q->whereRaw('1 = 0') : $q->whereIn('id', $regions))->orderBy('region_name')->get(['id', 'region_name']),
        ]);
    }
}
