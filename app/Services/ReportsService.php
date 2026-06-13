<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class ReportsService
{
    public function resolveDateRange(string $preset, ?string $customFrom = null, ?string $customTo = null): array
    {
        $today = today();

        return match ($preset) {
            'last_3_months' => [$today->copy()->subMonthsNoOverflow(2)->startOfMonth(), $today->copy()->endOfMonth()],
            'last_6_months' => [$today->copy()->subMonthsNoOverflow(5)->startOfMonth(), $today->copy()->endOfMonth()],
            'last_12_months' => [$today->copy()->subMonthsNoOverflow(11)->startOfMonth(), $today->copy()->endOfMonth()],
            'custom' => [
                $customFrom ? Carbon::parse($customFrom)->startOfDay() : $today->copy()->startOfMonth(),
                $customTo ? Carbon::parse($customTo)->endOfDay() : $today->copy()->endOfDay(),
            ],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
        };
    }

    public function reportPayload(Carbon $from, Carbon $to, ?int $deptId): array
    {
        return [
            'statCards' => $this->statCards($from, $to, $deptId),
            'monthlyExpenses' => $this->expenseSummary($from, $to, $deptId),
            'expenseByType' => $this->expenseByType($from, $to, $deptId),
            'vehicleStatusCounts' => $this->vehicleStatusCounts($deptId),
            'vehiclesByDepartment' => $this->vehiclesByDepartment(),
            'mileageByMonth' => $this->mileageByMonth($from, $to, $deptId),
            'issuesTrend' => $this->issuesTrend($from, $to, $deptId),
            'topExpensiveVehicles' => $this->topExpensiveVehicles($from, $to, 5, $deptId),
            'issuesByType' => $this->issuesByType($from, $to, $deptId),
            'upcomingExpiryDocs' => $this->upcomingExpiryDocs(60, $deptId),
            'maintenanceDueSoon' => $this->maintenanceDueSoon(6, $deptId),
        ];
    }

    public function expenseSummary(Carbon $from, Carbon $to, ?int $deptId): array
    {
        return $this->remember(__FUNCTION__, compact('from', 'to', 'deptId'), function () use ($from, $to, $deptId): array {
            $months = $this->monthBuckets($from, $to);
            $totals = $this->expenseQuery($from, $to, $deptId)
                ->get(['amount', 'expense_date'])
                ->groupBy(fn (VehicleExpense $expense) => $expense->expense_date?->format('Y-m'))
                ->map(fn ($items) => (float) $items->sum('amount'));

            return [
                'labels' => $months->pluck('label')->values()->all(),
                'data' => $months->map(fn (array $month) => round((float) ($totals[$month['key']] ?? 0), 2))->values()->all(),
                'total' => round((float) $totals->sum(), 2),
            ];
        });
    }

    public function expenseByType(Carbon $from, Carbon $to, ?int $deptId): array
    {
        return $this->remember(__FUNCTION__, compact('from', 'to', 'deptId'), function () use ($from, $to, $deptId): array {
            $totals = $this->expenseQuery($from, $to, $deptId)
                ->get(['expense_type', 'amount'])
                ->groupBy('expense_type')
                ->map(fn ($items) => round((float) $items->sum('amount'), 2));

            $types = VehicleExpense::TYPES;

            return [
                'labels' => collect($types)->map(fn (string $type) => str($type)->replace('_', ' ')->title()->toString())->all(),
                'keys' => $types,
                'data' => collect($types)->map(fn (string $type) => (float) ($totals[$type] ?? 0))->all(),
            ];
        });
    }

    public function vehicleStatusCounts(?int $deptId): array
    {
        return $this->remember(__FUNCTION__, compact('deptId'), function () use ($deptId): array {
            $counts = $this->vehicleQuery($deptId)
                ->get(['status'])
                ->groupBy('status')
                ->map->count();

            return [
                'labels' => collect(Vehicle::STATUSES)->map(fn (string $status) => str($status)->replace('_', ' ')->title()->toString())->all(),
                'keys' => Vehicle::STATUSES,
                'data' => collect(Vehicle::STATUSES)->map(fn (string $status) => (int) ($counts[$status] ?? 0))->all(),
            ];
        });
    }

    public function vehiclesByDepartment(): array
    {
        return $this->remember(__FUNCTION__, [], function (): array {
            $rows = Vehicle::query()
                ->with('department')
                ->get()
                ->groupBy(fn (Vehicle $vehicle) => $vehicle->department?->department_name ?: 'Unassigned')
                ->map(fn ($items, string $department) => [
                    'department' => $department,
                    'count' => $items->count(),
                ])
                ->sortByDesc('count')
                ->values();

            return [
                'labels' => $rows->pluck('department')->all(),
                'data' => $rows->pluck('count')->all(),
                'rows' => $rows->all(),
            ];
        });
    }

    public function mileageByMonth(Carbon $from, Carbon $to, ?int $deptId): array
    {
        return $this->remember(__FUNCTION__, compact('from', 'to', 'deptId'), function () use ($from, $to, $deptId): array {
            $months = $this->monthBuckets($from, $to);
            $totals = \App\Models\MileageLog::query()
                ->with('vehicle')
                ->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])
                ->when($deptId, fn (Builder $query) => $query->whereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('department_id', $deptId)))
                ->get(['distance_driven', 'trip_date'])
                ->groupBy(fn ($log) => $log->trip_date?->format('Y-m'))
                ->map(fn ($items) => (int) $items->sum('distance_driven'));

            return [
                'labels' => $months->pluck('label')->values()->all(),
                'data' => $months->map(fn (array $month) => (int) ($totals[$month['key']] ?? 0))->values()->all(),
            ];
        });
    }

    public function issuesTrend(Carbon $from, Carbon $to, ?int $deptId): array
    {
        return $this->remember(__FUNCTION__, compact('from', 'to', 'deptId'), function () use ($from, $to, $deptId): array {
            $months = $this->monthBuckets($from, $to);
            $reported = $this->issueQuery($deptId)
                ->whereBetween('reported_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->get(['reported_at'])
                ->groupBy(fn (VehicleIssue $issue) => $issue->reported_at?->format('Y-m'))
                ->map->count();
            $resolved = $this->issueQuery($deptId)
                ->whereBetween('resolved_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->get(['resolved_at'])
                ->groupBy(fn (VehicleIssue $issue) => $issue->resolved_at?->format('Y-m'))
                ->map->count();

            return [
                'labels' => $months->pluck('label')->values()->all(),
                'reported' => $months->map(fn (array $month) => (int) ($reported[$month['key']] ?? 0))->values()->all(),
                'resolved' => $months->map(fn (array $month) => (int) ($resolved[$month['key']] ?? 0))->values()->all(),
            ];
        });
    }

    public function topExpensiveVehicles(Carbon $from, Carbon $to, int $limit = 5, ?int $deptId = null): array
    {
        return $this->remember(__FUNCTION__, compact('from', 'to', 'limit', 'deptId'), function () use ($from, $to, $limit, $deptId): array {
            $rows = $this->expenseQuery($from, $to, $deptId)
                ->with('vehicle')
                ->get()
                ->groupBy('vehicle_id')
                ->map(fn ($items) => [
                    'vehicle' => $items->first()->vehicle?->number_plate ?: 'Unknown',
                    'total' => round((float) $items->sum('amount'), 2),
                ])
                ->sortByDesc('total')
                ->take($limit)
                ->values();

            return [
                'labels' => $rows->pluck('vehicle')->all(),
                'data' => $rows->pluck('total')->all(),
                'rows' => $rows->all(),
            ];
        });
    }

    public function issuesByType(Carbon $from, Carbon $to, ?int $deptId = null): array
    {
        return $this->remember(__FUNCTION__, compact('from', 'to', 'deptId'), function () use ($from, $to, $deptId): array {
            $counts = [];

            $this->issueQuery($deptId)
                ->whereBetween('reported_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->get(['issue_types'])
                ->each(function (VehicleIssue $issue) use (&$counts): void {
                    foreach ($issue->issue_types ?? [] as $type) {
                        $counts[$type] = ($counts[$type] ?? 0) + 1;
                    }
                });

            $types = ['engine', 'tires', 'lights', 'brakes', 'battery', 'bodywork', 'suspension', 'other'];
            $max = max(1, (int) max($counts ?: [0]));
            $colors = [
                'engine' => '#a32d2d',
                'tires' => '#b7791f',
                'lights' => '#185fa5',
                'brakes' => '#21633c',
                'battery' => '#6b46c1',
                'bodywork' => '#66758b',
                'suspension' => '#0f766e',
                'other' => '#475569',
            ];

            $rows = collect($types)->map(fn (string $type) => [
                'key' => $type,
                'label' => str($type)->replace('_', ' ')->title()->toString(),
                'count' => (int) ($counts[$type] ?? 0),
                'percent' => round(((int) ($counts[$type] ?? 0) / $max) * 100),
                'color' => $colors[$type] ?? '#475569',
                'abbr' => str($type)->substr(0, 2)->upper()->toString(),
            ])->values();

            return [
                'rows' => $rows->all(),
                'labels' => $rows->pluck('label')->all(),
                'data' => $rows->pluck('count')->all(),
            ];
        });
    }

    public function upcomingExpiryDocs(int $days = 60, ?int $deptId = null): array
    {
        return $this->remember(__FUNCTION__, compact('days', 'deptId'), function () use ($days, $deptId): array {
            $today = today();
            $until = $today->copy()->addDays($days);
            $rows = collect();

            $this->vehicleQuery($deptId)
                ->where(function (Builder $query) use ($today, $until): void {
                    $query->whereBetween('insurance_expiry_date', [$today->toDateString(), $until->toDateString()])
                        ->orWhereBetween('road_worthiness_expiry_date', [$today->toDateString(), $until->toDateString()]);
                })
                ->get()
                ->each(function (Vehicle $vehicle) use ($today, $until, $rows): void {
                    foreach ([
                        'insurance' => $vehicle->insurance_expiry_date,
                        'road_worthiness' => $vehicle->road_worthiness_expiry_date,
                    ] as $type => $date) {
                        if (! $date || $date->lt($today) || $date->gt($until)) {
                            continue;
                        }

                        $daysRemaining = (int) $today->diffInDays($date);
                        $rows->push([
                            'vehicle' => $vehicle->number_plate,
                            'document' => str($type)->replace('_', ' ')->title()->toString(),
                            'expiry_date' => $date->toDateString(),
                            'days_remaining' => $daysRemaining,
                            'badge' => $daysRemaining < 15 ? 'red' : 'amber',
                        ]);
                    }
                });

            return $rows->sortBy('days_remaining')->values()->all();
        });
    }

    public function maintenanceDueSoon(int $limit = 6, ?int $deptId = null): array
    {
        return $this->remember(__FUNCTION__, compact('limit', 'deptId'), function () use ($limit, $deptId): array {
            $rows = $this->vehicleQuery($deptId)
                ->where('status', '!=', Vehicle::STATUS_RETIRED)
                ->get()
                ->map(fn (Vehicle $vehicle) => [
                    'vehicle' => $vehicle->number_plate,
                    'current_mileage' => (int) $vehicle->current_mileage,
                    'next_maintenance_mileage' => (int) $vehicle->next_maintenance_mileage,
                    'remaining_km' => (int) $vehicle->maintenance_remaining_km,
                    'remaining_color' => $vehicle->maintenance_remaining_km <= 0
                        ? '#a32d2d'
                        : ($vehicle->maintenance_remaining_km < 1500 ? '#b7791f' : '#21633c'),
                ])
                ->sortBy('remaining_km')
                ->take($limit)
                ->values();

            return [
                'labels' => $rows->pluck('vehicle')->all(),
                'current' => $rows->pluck('current_mileage')->all(),
                'remaining' => $rows->pluck('remaining_km')->all(),
                'remainingColors' => $rows->pluck('remaining_color')->all(),
                'rows' => $rows->all(),
            ];
        });
    }

    public function statCards(Carbon $from, Carbon $to, ?int $deptId): array
    {
        return $this->remember(__FUNCTION__, compact('from', 'to', 'deptId'), function () use ($from, $to, $deptId): array {
            $periodDays = max(1, $from->diffInDays($to) + 1);
            $priorTo = $from->copy()->subDay()->endOfDay();
            $priorFrom = $priorTo->copy()->subDays($periodDays - 1)->startOfDay();
            $spend = (float) $this->expenseQuery($from, $to, $deptId)->sum('amount');
            $priorSpend = (float) $this->expenseQuery($priorFrom, $priorTo, $deptId)->sum('amount');
            $change = $priorSpend > 0 ? (($spend - $priorSpend) / $priorSpend) * 100 : ($spend > 0 ? 100 : 0);
            $vehicles = $this->vehicleQuery($deptId)->get();
            $issues = $this->issueQuery($deptId)->where('status', '!=', VehicleIssue::STATUS_RESOLVED)->get();
            $docs30 = count($this->upcomingExpiryDocs(30, $deptId));

            return [
                [
                    'label' => 'Total Vehicles',
                    'value' => $vehicles->count(),
                    'badge' => $vehicles->where('status', Vehicle::STATUS_ACTIVE)->count().' active',
                    'tone' => 'blue',
                ],
                [
                    'label' => 'In Maintenance',
                    'value' => $vehicles->where('status', Vehicle::STATUS_MAINTENANCE)->count(),
                    'badge' => $vehicles->filter(fn (Vehicle $vehicle) => $vehicle->maintenance_remaining_km <= 0)->count().' overdue',
                    'tone' => 'amber',
                ],
                [
                    'label' => 'Open Issues',
                    'value' => $issues->count(),
                    'badge' => $issues->where('severity', 'critical')->count().' critical',
                    'tone' => 'red',
                ],
                [
                    'label' => 'Fleet Spend',
                    'value' => 'GHS '.number_format($spend, 2),
                    'badge' => sprintf('%+.1f%% vs prior', $change),
                    'tone' => $change > 0 ? 'amber' : 'green',
                ],
                [
                    'label' => 'Docs Expiring',
                    'value' => $docs30,
                    'badge' => $docs30 > 0 ? 'action needed' : 'clear',
                    'tone' => $docs30 > 0 ? 'red' : 'green',
                ],
            ];
        });
    }

    protected function expenseQuery(Carbon $from, Carbon $to, ?int $deptId): Builder
    {
        return VehicleExpense::query()
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($deptId, fn (Builder $query) => $query->whereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('department_id', $deptId)));
    }

    protected function issueQuery(?int $deptId): Builder
    {
        return VehicleIssue::query()
            ->when($deptId, fn (Builder $query) => $query->whereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('department_id', $deptId)));
    }

    protected function vehicleQuery(?int $deptId): Builder
    {
        return Vehicle::query()
            ->when($deptId, fn (Builder $query) => $query->where('department_id', $deptId));
    }

    protected function monthBuckets(Carbon $from, Carbon $to)
    {
        return collect(CarbonPeriod::create($from->copy()->startOfMonth(), '1 month', $to->copy()->startOfMonth()))
            ->map(fn (Carbon $month) => [
                'key' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
            ])
            ->values();
    }

    protected function remember(string $method, array $params, Closure $callback): array
    {
        $normalized = collect($params)
            ->map(fn ($value) => $value instanceof Carbon ? $value->toDateString() : $value)
            ->all();

        return Cache::remember(
            'transport_reports:'.$method.':'.md5(json_encode($normalized)),
            now()->addMinutes(5),
            $callback
        );
    }
}
