<?php

namespace App\Livewire\Transport;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use App\Repositories\Transport\VehicleRepository;
use App\Services\Transport\TransportScope;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Dashboard extends Component
{
    use EnforcesModuleAccess;

    public function mount(): void
    {
        $this->enforceLivewireModule('transport');
    }

    public function render(VehicleRepository $vehicles, TransportScope $scope)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $canManage = $scope->can($user, 'transport.view_dashboard') || $scope->can($user, 'transport.view_vehicles');
        $canLogMileage = $scope->can($user, 'transport.log_mileage');
        $canReportIssue = $scope->can($user, 'transport.report_issues');

        $assignedVehicle = $vehicles->assignedTo($user);

        $stats = [
            'total' => $scope->vehicles($user)->count(),
            'active' => $scope->vehicles($user)->where('vehicles.status', Vehicle::STATUS_ACTIVE)->count(),
            'maintenance' => $scope->vehicles($user)->where('vehicles.status', Vehicle::STATUS_MAINTENANCE)->count(),
            'retired' => $scope->vehicles($user)->where('vehicles.status', Vehicle::STATUS_RETIRED)->count(),
            'open_issues' => VehicleIssue::query()->visibleTo($user)->where('status', '!=', VehicleIssue::STATUS_RESOLVED)->count(),
            'month_spend' => VehicleExpense::query()
                ->visibleTo($user)
                ->whereBetween('expense_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('amount'),
        ];

        $departmentBreakdown = $scope->vehicles($user)
            ->leftJoin('departments', 'departments.id', '=', 'vehicles.department_id')
            ->groupBy('vehicles.department_id', 'departments.department_name')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(8)
            ->get([
                DB::raw("COALESCE(departments.department_name, 'Unassigned') as department_name"),
                DB::raw('COUNT(*) as total'),
            ]);

        $upcomingMaintenance = $scope->vehicles($user)
            ->with(['assignedDriver'])
            ->where('vehicles.status', '!=', Vehicle::STATUS_RETIRED)
            ->get()
            ->sortBy('maintenance_remaining_km')
            ->take(8);

        $expiringDocuments = $scope->vehicles($user)
            ->where(function ($query): void {
                $query->whereBetween('insurance_expiry_date', [today()->toDateString(), today()->addDays(90)->toDateString()])
                    ->orWhereBetween('road_worthiness_expiry_date', [today()->toDateString(), today()->addDays(90)->toDateString()]);
            })
            ->orderBy('insurance_expiry_date')
            ->limit(10)
            ->get();

        $issuesByStatus = VehicleIssue::query()
            ->visibleTo($user)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $mostExpensive = VehicleExpense::query()
            ->visibleTo($user)
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_expenses.vehicle_id')
            ->groupBy('vehicle_expenses.vehicle_id', 'vehicles.number_plate', 'vehicles.brand', 'vehicles.model')
            ->orderByDesc(DB::raw('SUM(vehicle_expenses.amount)'))
            ->limit(6)
            ->get([
                'vehicles.number_plate',
                'vehicles.brand',
                'vehicles.model',
                DB::raw('SUM(vehicle_expenses.amount) as total_spend'),
            ]);

        return view('livewire.transport.dashboard', [
            'canManage' => $canManage,
            'canLogMileage' => $canLogMileage,
            'canReportIssue' => $canReportIssue,
            'assignedVehicle' => $assignedVehicle,
            'stats' => $stats,
            'departmentBreakdown' => $departmentBreakdown,
            'upcomingMaintenance' => $upcomingMaintenance,
            'expiringDocuments' => $expiringDocuments,
            'issuesByStatus' => $issuesByStatus,
            'mostExpensive' => $mostExpensive,
        ]);
    }
}
