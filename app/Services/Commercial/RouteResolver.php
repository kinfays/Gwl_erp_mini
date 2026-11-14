<?php

namespace App\Services\Commercial;

use App\Models\CommercialRoute;
use App\Models\District;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The route master's one service (design 8.30): turns what a report prints into a route, and keeps the "same code under several
 * districts" conflicts decided.
 *
 *   "AMASAMAN 4601"                  billing / reading: DISTRICT code
 *   "4601, KANESHIE, ACCRA WEST"     customer list: code, DISTRICT, REGION
 *
 * A route is (district, code). The district name goes through the existing alias mechanism (LocationMatcher /
 * commercial_location_aliases). A label never seen before creates the route PENDING. When two routes of one region share a code,
 * exactly one is canonical: a route a Commercial Manager pinned (`pinned`) wins, otherwise the one whose district comes from
 * the system named by `commercial_route_district_authority` (billing = the label billing AND reading print, or customer_list),
 * otherwise the oldest; the others point at it with canonical_route_id. resolve() always returns the CANONICAL route.
 *
 * Nothing here deletes a route, and every change a person makes is audited (ids and codes only, never a customer).
 */
class RouteResolver
{
    public function __construct(protected LocationMatcher $locations)
    {
    }

    // ---------------------------------------------------------------- reading a label

    /**
     * @return array{code: string|null, district: string|null, region: string|null}
     */
    public function parse(string $label): array
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);

        if ($label === '') {
            return ['code' => null, 'district' => null, 'region' => null];
        }

        // customer list: "code, DISTRICT, REGION" (the code may itself contain commas, so the last two parts are district and region)
        if (str_contains($label, ',')) {
            $parts = array_map('trim', explode(',', $label));

            if (count($parts) >= 3) {
                $region = array_pop($parts);
                $district = array_pop($parts);

                return ['code' => implode(', ', $parts) ?: null, 'district' => $district !== '' ? $district : null, 'region' => $region !== '' ? $region : null];
            }

            if (count($parts) === 2) {
                return ['code' => $parts[0] ?: null, 'district' => $parts[1] !== '' ? $parts[1] : null, 'region' => null];
            }
        }

        // billing / reading: "DISTRICT code" (the code is the last token), or a bare code
        if (preg_match('/^(.*?)\s*\b(\d{1,12})$/u', $label, $m)) {
            return ['code' => $m[2], 'district' => trim($m[1]) !== '' ? trim($m[1]) : null, 'region' => null];
        }

        return ['code' => $label, 'district' => null, 'region' => null];
    }

    // ---------------------------------------------------------------- resolving

    /**
     * The CANONICAL route a label stands for, created pending when unseen; null when the district it names cannot be placed (the
     * caller reports it: nothing is guessed).
     */
    public function resolve(string $label, ?int $regionId, string $source): ?CommercialRoute
    {
        $parsed = $this->parse($label);

        if ($parsed['code'] === null) {
            return null;
        }

        if ($regionId === null && $parsed['region'] !== null) {
            $regionId = $this->locations->resolveRegion($parsed['region'])?->id;
        }

        $district = $parsed['district'] !== null ? $this->locations->resolveDistrict($parsed['district'], $regionId) : null;

        if (! $district) {
            return null;
        }

        return $this->resolveIn($district, $parsed['code'], $source);
    }

    /** The canonical route for a code in a known district (the form the importers use once they have matched the district). */
    public function resolveIn(District $district, string $code, string $source): CommercialRoute
    {
        $code = $this->code($code);
        $route = CommercialRoute::query()->where('district_id', $district->id)->where(fn ($q) => $q->where('code', $code)->orWhere('name', $code))->orderBy('id')->first();

        if (! $route) {
            $route = CommercialRoute::query()->create([
                'district_id' => $district->id, 'region_id' => $district->region_id, 'name' => $code, 'code' => $code,
                'source' => $source, 'active' => true, 'is_pending' => true,
            ]);
        } else {
            $update = [];

            if ($route->code === null) {
                $update['code'] = $code;
            }

            if ($route->region_id === null) {
                $update['region_id'] = $district->region_id;
            }

            // a system that shows the same (district, code) pair as the authority does makes the route its own
            if (! $route->pinned && $this->group($source) === $this->authority() && $this->group($route->source) !== $this->authority()) {
                $update['source'] = $source;
            }

            $update === [] || $route->update($update);
        }

        $this->settle((int) $route->region_id, (string) $route->code);

        return $this->canonical($route->fresh());
    }

    /** Follows an alias to the route it stands for. */
    public function canonical(CommercialRoute $route): CommercialRoute
    {
        $hops = 0;

        while ($route->canonical_route_id !== null && $hops++ < 5) {
            $next = CommercialRoute::query()->find($route->canonical_route_id);

            if (! $next) {
                break;
            }

            $route = $next;
        }

        return $route;
    }

    // ---------------------------------------------------------------- the canonical election

    /** The authority the Settings name: "billing" (billing AND reading labels) or "customer_list". */
    public function authority(): string
    {
        return config('gwl.commercial_route_district_authority') === 'customer_list' ? 'customer_list' : 'billing';
    }

    /** reading and billing print the same label, so both count as "billing" for the authority. */
    protected function group(?string $source): string
    {
        return $source === CommercialRoute::SOURCE_CUSTOMER_LIST ? 'customer_list' : ($source === CommercialRoute::SOURCE_MANUAL ? 'manual' : 'billing');
    }

    protected function code(string $code): string
    {
        return mb_substr(trim($code), 0, 12);
    }

    /**
     * Decides which of the routes of one region that share a code is canonical, and points the others at it. A manager's alias
     * stays as they set it; a pinned route is canonical.
     */
    public function settle(int $regionId, string $code): void
    {
        if ($regionId === 0 || $code === '') {
            return;
        }

        /** @var Collection<int, CommercialRoute> $siblings */
        $siblings = CommercialRoute::query()->where('region_id', $regionId)->where('code', $code)->where('active', true)->orderBy('id')->get();

        if ($siblings->count() < 2) {
            foreach ($siblings as $only) {
                if ($only->canonical_route_id !== null && ! $only->pinned) {
                    $only->update(['canonical_route_id' => null]);
                }
            }

            return;
        }

        $pinned = $siblings->first(fn (CommercialRoute $route) => $route->pinned && $route->canonical_route_id === null);
        $elect = $pinned
            ?? $siblings->first(fn (CommercialRoute $route) => ! $route->pinned && $this->group($route->source) === $this->authority())
            ?? $siblings->first(fn (CommercialRoute $route) => ! $route->pinned)
            ?? $siblings->first();

        foreach ($siblings as $route) {
            if ($route->pinned && $route->canonical_route_id !== null) {
                continue;   // a manager's alias stays
            }

            $target = $route->id === $elect->id ? null : $elect->id;

            if ($route->canonical_route_id !== $target) {
                $route->update(['canonical_route_id' => $target]);
            }
        }
    }

    /** Settles every code of every region again (after the authority setting changed). @return int codes that were shared */
    public function settleAll(): int
    {
        $shared = CommercialRoute::query()->whereNotNull('region_id')->whereNotNull('code')->where('active', true)
            ->select('region_id', 'code')->groupBy('region_id', 'code')->havingRaw('COUNT(*) > 1')->get();

        foreach ($shared as $pair) {
            $this->settle((int) $pair->region_id, (string) $pair->code);
        }

        return $shared->count();
    }

    // ---------------------------------------------------------------- what billing shows (registered after an import)

    /**
     * Registers the routes of a billing batch in the route master (source billing). Routes whose district was not matched are
     * skipped: they are resolved on the batch screen first.
     *
     * @param  iterable<array{district_id: int|null, route_code: string}>  $routes
     * @return int routes created (pending)
     */
    public function registerRoutes(iterable $routes, string $source): int
    {
        $created = 0;
        $districts = [];

        foreach ($routes as $route) {
            $districtId = $route['district_id'] ?? null;

            if (! $districtId) {
                continue;
            }

            $districts[$districtId] ??= District::query()->find($districtId);

            if (! $districts[$districtId]) {
                continue;
            }

            $parsed = $this->parse((string) $route['route_code']);
            $before = CommercialRoute::query()->count();
            $this->resolveIn($districts[$districtId], $parsed['code'] ?? (string) $route['route_code'], $source);
            $created += CommercialRoute::query()->count() > $before ? 1 : 0;
        }

        return $created;
    }

    // ---------------------------------------------------------------- what a Commercial Manager does

    /** Pins a route to a district (the district its customers are reported under) and makes it the canonical one for its code. */
    public function pin(int $routeId, int $districtId, ?int $actorId = null): CommercialRoute
    {
        $route = CommercialRoute::query()->findOrFail($routeId);
        $district = District::query()->findOrFail($districtId);

        if ($route->region_id !== null && (int) $district->region_id !== (int) $route->region_id) {
            throw ValidationException::withMessages(['district' => 'A route can only be pinned to a district of its own region.']);
        }

        $clash = CommercialRoute::query()->where('district_id', $district->id)->where('name', $route->name)->whereKeyNot($route->id)->first();

        if ($clash) {
            throw ValidationException::withMessages(['district' => 'That district already has a route with this code: mark this one as its alias instead.']);
        }

        $oldDistrict = $route->district_id;
        $route->update(['district_id' => $district->id, 'region_id' => $district->region_id, 'pinned' => true, 'canonical_route_id' => null, 'is_pending' => false]);
        $this->settle((int) $route->region_id, (string) $route->code);

        $this->audit('commercial.route_pinned', $route, ['from_district_id' => $oldDistrict, 'to_district_id' => $district->id, 'code' => $route->code]);

        return $route->fresh();
    }

    /** Marks a route as an alias of another route of the same region and code. */
    public function markAlias(int $routeId, int $canonicalId): CommercialRoute
    {
        $route = CommercialRoute::query()->findOrFail($routeId);
        $canonical = $this->canonical(CommercialRoute::query()->findOrFail($canonicalId));

        if ($canonical->id === $route->id) {
            throw ValidationException::withMessages(['canonical' => 'A route cannot be its own alias.']);
        }

        if ($route->region_id !== $canonical->region_id || (string) $route->code !== (string) $canonical->code) {
            throw ValidationException::withMessages(['canonical' => 'An alias must have the same code and be in the same region as the route it points to.']);
        }

        $route->update(['canonical_route_id' => $canonical->id, 'pinned' => true, 'is_pending' => false]);

        $this->audit('commercial.route_marked_alias', $route, ['canonical_route_id' => $canonical->id, 'code' => $route->code]);

        return $route->fresh();
    }

    /** Takes a route back out of the manual rule: the authority setting decides again. */
    public function release(int $routeId): CommercialRoute
    {
        $route = CommercialRoute::query()->findOrFail($routeId);
        $route->update(['pinned' => false, 'canonical_route_id' => null]);
        $this->settle((int) $route->region_id, (string) $route->code);

        $this->audit('commercial.route_released', $route, ['code' => $route->code]);

        return $route->fresh();
    }

    /** A route nobody needs any more (kept, hidden). */
    public function setActive(int $routeId, bool $active): CommercialRoute
    {
        $route = CommercialRoute::query()->findOrFail($routeId);
        $route->update(['active' => $active]);

        $this->audit($active ? 'commercial.route_activated' : 'commercial.route_deactivated', $route, ['code' => $route->code]);

        return $route->fresh();
    }

    /** Confirms a pending route (somebody looked at it). */
    public function confirm(int $routeId): CommercialRoute
    {
        $route = CommercialRoute::query()->findOrFail($routeId);
        $route->update(['is_pending' => false]);

        $this->audit('commercial.route_confirmed', $route, ['code' => $route->code]);

        return $route->fresh();
    }

    /** Settles every shared code again under the current authority setting. */
    public function rebuild(): int
    {
        $count = $this->settleAll();

        Audit::log(action: 'commercial.routes_rebuilt', module: Permission::MODULE_COMMERCIAL, metadata: ['shared_codes' => $count, 'authority' => $this->authority()]);

        return $count;
    }

    // ---------------------------------------------------------------- the X10 conflicts

    /**
     * The codes printed under more than one district in a region, and the routes of 0000 (no route). Counts only.
     *
     * @return array{conflicts: list<array{region_id: int, code: string, routes: int}>, unassigned: int}
     */
    public function conflicts(?array $regionIds = null): array
    {
        $shared = CommercialRoute::query()->whereNotNull('region_id')->whereNotNull('code')->where('active', true)
            ->when($regionIds !== null, fn ($q) => $regionIds === [] ? $q->whereRaw('1 = 0') : $q->whereIn('region_id', $regionIds))
            ->select('region_id', 'code', DB::raw('COUNT(DISTINCT district_id) AS districts'))->groupBy('region_id', 'code')->havingRaw('COUNT(DISTINCT district_id) > 1')->get();

        return [
            'conflicts' => $shared->map(fn ($row) => ['region_id' => (int) $row->region_id, 'code' => (string) $row->code, 'routes' => (int) $row->districts])->values()->all(),
            'unassigned' => (int) CommercialRoute::query()->where('code', CommercialRoute::UNASSIGNED_CODE)->when($regionIds !== null, fn ($q) => $regionIds === [] ? $q->whereRaw('1 = 0') : $q->whereIn('region_id', $regionIds))->count(),
        ];
    }

    protected function audit(string $action, CommercialRoute $route, array $metadata): void
    {
        Audit::log(action: $action, module: Permission::MODULE_COMMERCIAL, targetType: CommercialRoute::class, targetId: $route->id, metadata: $metadata);
    }
}
