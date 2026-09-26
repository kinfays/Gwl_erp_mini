<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\MaintenanceRecord;
use App\Models\MileageLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignmentHistory;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TransportSeeder extends Seeder
{
    /**
     * DatabaseSeeder mutes model events (WithoutModelEvents), but the transport
     * models derive columns in theirs: HasUuid fills `uuid`, MileageLog computes
     * `distance_driven`, VehicleIssue defaults `reported_at`/`status`. Seed with
     * the real dispatcher, exactly as when this seeder runs on its own.
     */
    public function run(): void
    {
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(app('events'));

        try {
            $this->seedFleet();
        } finally {
            $dispatcher ? Model::setEventDispatcher($dispatcher) : Model::unsetEventDispatcher();
        }
    }

    protected function seedFleet(): void
    {
        $departments = collect(['Transport', 'Operations', 'Commercial', 'Finance', 'Administration'])
            ->mapWithKeys(fn (string $name) => [$name => Department::query()->firstOrCreate(['department_name' => $name])]);

        $manager = $this->user('TRM001', 'transport.manager@ml.local', 'Transport Manager', 'transport_manager');
        $drivers = [
            'one' => $this->user('DRV001', 'driver.one@ml.local', 'Driver One', 'driver'),
            'two' => $this->user('DRV002', 'driver.two@ml.local', 'Driver Two', 'driver'),
            'three' => $this->user('DRV003', 'driver.three@ml.local', 'Driver Three', 'driver'),
        ];
        $employees = [
            'operations' => $this->user('EMPTR001', 'transport.employee@ml.local', 'Transport Employee', 'employee'),
            'commercial' => $this->user('EMPTR002', 'commercial.vehicle@ml.local', 'Commercial Vehicle User', 'employee'),
            'finance' => $this->user('EMPTR003', 'finance.vehicle@ml.local', 'Finance Vehicle User', 'employee'),
        ];

        $fleet = [
            [
                'number_plate' => 'GV-2410-26',
                'type' => Vehicle::TYPE_PICKUP,
                'brand' => 'Toyota',
                'model' => 'Hilux',
                'color' => 'White',
                'year_purchased' => 2022,
                'department' => 'Operations',
                'status' => Vehicle::STATUS_ACTIVE,
                'current_mileage' => 48200,
                'insurance_days' => 45,
                'road_days' => 25,
                'employee' => $employees['operations'],
                'driver' => $drivers['one'],
                'assigned_days' => 20,
                'assignment_notes' => 'Regional operations pickup assigned for field inspections.',
                'mileage' => [
                    [48020, 48200, 1, 'Regional field visit'],
                    [47700, 48020, 5, 'District water quality inspection'],
                ],
                'issues' => [
                    [['tires'], 'Front tire pressure drops after long trips.', 'medium', VehicleIssue::STATUS_IN_REVIEW, 3],
                ],
                'maintenance' => [
                    ['routine_service', 'Oil change, filters, and brake inspection.', 'Approved Auto Clinic', 45000, 1850, 32, 60, 50000],
                ],
                'expenses' => [
                    ['fuel', 650, 'Fuel for regional movement.', 2],
                    ['parking', 35, 'Parking during partner meeting.', 8],
                ],
            ],
            [
                'number_plate' => 'GV-1184-25',
                'type' => Vehicle::TYPE_VAN,
                'brand' => 'Nissan',
                'model' => 'Urvan',
                'color' => 'Silver',
                'year_purchased' => 2020,
                'department' => 'Transport',
                'status' => Vehicle::STATUS_MAINTENANCE,
                'current_mileage' => 75200,
                'insurance_days' => 100,
                'road_days' => 70,
                'employee' => null,
                'driver' => null,
                'mileage' => [
                    [74880, 75200, 7, 'Staff shuttle route'],
                ],
                'issues' => [
                    [['brakes'], 'Brake pedal feels soft during staff shuttle runs.', 'high', VehicleIssue::STATUS_IN_MAINTENANCE, 6],
                ],
                'maintenance' => [
                    ['repair', 'Brake system diagnosis and replacement of worn pads.', 'Tema Fleet Services', 75200, 4200, 4, 90, 80200],
                ],
                'expenses' => [
                    ['repair', 4200, 'Brake replacement deposit.', 4],
                ],
            ],
            [
                'number_plate' => 'GV-3022-26',
                'type' => Vehicle::TYPE_SEDAN,
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'color' => 'Blue',
                'year_purchased' => 2024,
                'department' => 'Finance',
                'status' => Vehicle::STATUS_ACTIVE,
                'current_mileage' => 18640,
                'insurance_days' => 12,
                'road_days' => 58,
                'employee' => $employees['finance'],
                'driver' => null,
                'assigned_days' => 42,
                'assignment_notes' => 'Finance office vehicle for bank and audit errands.',
                'mileage' => [
                    [18510, 18640, 2, 'Bank document submission'],
                    [18320, 18510, 10, 'Supplier payment follow-up'],
                ],
                'issues' => [
                    [['lights'], 'Left rear brake light replaced after report.', 'low', VehicleIssue::STATUS_RESOLVED, 14],
                ],
                'maintenance' => [
                    ['inspection', 'Quarterly inspection with light replacement.', 'North Ridge Auto Care', 18000, 740, 13, 75, 23000],
                ],
                'expenses' => [
                    ['fuel', 420, 'Fuel for finance errands.', 2],
                    ['repair', 120, 'Brake light bulb replacement.', 13],
                    ['insurance', 2950, 'Annual insurance renewal.', 5],
                ],
            ],
            [
                'number_plate' => 'GV-7765-24',
                'type' => Vehicle::TYPE_PICKUP,
                'brand' => 'Ford',
                'model' => 'Ranger',
                'color' => 'Black',
                'year_purchased' => 2021,
                'department' => 'Commercial',
                'status' => Vehicle::STATUS_ACTIVE,
                'current_mileage' => 60880,
                'insurance_days' => 82,
                'road_days' => 18,
                'employee' => $employees['commercial'],
                'driver' => $drivers['two'],
                'assigned_days' => 64,
                'assignment_notes' => 'Commercial field recovery vehicle.',
                'mileage' => [
                    [60440, 60880, 3, 'Commercial field recovery'],
                    [60010, 60440, 11, 'Meter inspection rounds'],
                ],
                'issues' => [
                    [['battery'], 'Battery struggles to start in the morning.', 'medium', VehicleIssue::STATUS_OPEN, 2],
                ],
                'maintenance' => [
                    ['routine_service', 'Routine service and suspension checks.', 'Accra Fleet Garage', 58000, 2450, 40, 45, 63000],
                ],
                'expenses' => [
                    ['fuel', 780, 'Fuel for commercial field recovery.', 3],
                    ['toll', 48, 'Toll charges for district rounds.', 11],
                ],
            ],
            [
                'number_plate' => 'GV-4421-23',
                'type' => Vehicle::TYPE_BUS,
                'brand' => 'Hyundai',
                'model' => 'County',
                'color' => 'White',
                'year_purchased' => 2019,
                'department' => 'Administration',
                'status' => Vehicle::STATUS_ACTIVE,
                'current_mileage' => 96310,
                'insurance_days' => 150,
                'road_days' => 7,
                'employee' => null,
                'driver' => $drivers['three'],
                'assigned_days' => 15,
                'assignment_notes' => 'Pool bus assigned to Driver Three for staff shuttle coverage.',
                'mileage' => [
                    [96040, 96310, 1, 'Head office shuttle'],
                    [95660, 96040, 9, 'Training programme transport'],
                ],
                'issues' => [
                    [['lights', 'bodywork'], 'Right indicator cover is cracked.', 'low', VehicleIssue::STATUS_OPEN, 1],
                ],
                'maintenance' => [
                    ['routine_service', 'Bus service and tyre rotation.', 'City Coach Workshop', 93000, 3900, 55, 35, 98000],
                ],
                'expenses' => [
                    ['fuel', 1120, 'Diesel for shuttle service.', 1],
                    ['road_worthiness', 860, 'Road worthiness processing fee.', 18],
                ],
            ],
            [
                'number_plate' => 'GV-0188-20',
                'type' => Vehicle::TYPE_MOTORCYCLE,
                'brand' => 'Honda',
                'model' => 'CB125',
                'color' => 'Red',
                'year_purchased' => 2018,
                'department' => 'Transport',
                'status' => Vehicle::STATUS_RETIRED,
                'current_mileage' => 44120,
                'insurance_days' => -20,
                'road_days' => -35,
                'employee' => null,
                'driver' => null,
                'mileage' => [
                    [43920, 44120, 92, 'Final courier assignment'],
                ],
                'issues' => [
                    [['engine'], 'Engine compression failure led to retirement recommendation.', 'critical', VehicleIssue::STATUS_RESOLVED, 88],
                ],
                'maintenance' => [
                    ['inspection', 'Retirement assessment completed.', 'Internal Workshop', 44120, 250, 85, null, null],
                ],
                'expenses' => [
                    ['maintenance', 250, 'Retirement assessment fee.', 85],
                ],
            ],
        ];

        foreach ($fleet as $demo) {
            $employee = $demo['employee'] ?? null;
            $driver = $demo['driver'] ?? null;
            $vehicle = Vehicle::query()->updateOrCreate(
                ['number_plate' => $demo['number_plate']],
                [
                    'type' => $demo['type'],
                    'brand' => $demo['brand'],
                    'model' => $demo['model'],
                    'color' => $demo['color'],
                    'year_purchased' => $demo['year_purchased'],
                    'is_pool_car' => $employee === null,
                    'assigned_user_id' => $employee?->id,
                    'department_id' => $departments[$demo['department']]->id,
                    'driver_type' => $driver ? Vehicle::DRIVER_ASSIGNED : Vehicle::DRIVER_SELF_DRIVE,
                    'assigned_driver_id' => $driver?->id,
                    'current_mileage' => $demo['current_mileage'],
                    'maintenance_interval_km' => 5000,
                    'insurance_expiry_date' => now()->addDays($demo['insurance_days'])->toDateString(),
                    'road_worthiness_expiry_date' => now()->addDays($demo['road_days'])->toDateString(),
                    'status' => $demo['status'],
                ]
            );

            $this->assignment($vehicle, $employee, $driver, $manager, $demo);

            foreach ($demo['mileage'] as $log) {
                $this->mileage($vehicle, $driver, $log);
            }

            foreach ($demo['issues'] as $issue) {
                $this->issue($vehicle, $driver ?? $manager, $issue);
            }

            foreach ($demo['maintenance'] as $maintenance) {
                $this->maintenance($vehicle, $maintenance);
            }

            foreach ($demo['expenses'] as $expense) {
                $this->expense($vehicle, $manager, $expense);
            }
        }
    }

    protected function user(string $staffId, string $email, string $name, string $roleName): User
    {
        $user = User::query()->firstOrCreate(
            ['staff_id' => $staffId],
            [
                'full_name' => $name,
                'email' => $email,
                'password' => Hash::make(User::DEFAULT_PASSWORD),
                'is_active' => true,
                'must_change_password' => true,
            ]
        );

        if (! $user->full_name) {
            $user->forceFill(['full_name' => $name])->save();
        }

        $role = Role::query()->where('name', $roleName)->first();

        if ($role && ! $user->roles()->where('roles.id', $role->id)->exists()) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    protected function assignment(Vehicle $vehicle, ?User $employee, ?User $driver, User $manager, array $demo): void
    {
        if (! $employee && ! $driver) {
            return;
        }

        VehicleAssignmentHistory::query()->updateOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'user_id' => $employee?->id,
                'driver_id' => $driver?->id,
                'unassigned_at' => null,
            ],
            [
                'assigned_by' => $manager->id,
                'assigned_at' => now()->subDays($demo['assigned_days'] ?? 10),
                'notes' => $demo['assignment_notes'] ?? 'Demo transport assignment.',
            ]
        );
    }

    protected function mileage(Vehicle $vehicle, ?User $driver, array $log): void
    {
        [$before, $after, $daysAgo, $purpose] = $log;
        $tripDate = now()->subDays($daysAgo)->toDateString();

        MileageLog::query()->firstOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'trip_date' => $tripDate,
                'trip_purpose' => $purpose,
            ],
            [
                'driver_id' => $driver?->id,
                'mileage_before' => $before,
                'mileage_after' => $after,
                'recorded_at' => now()->subDays($daysAgo),
            ]
        );
    }

    protected function issue(Vehicle $vehicle, User $reporter, array $issue): void
    {
        [$types, $description, $severity, $status, $daysAgo] = $issue;

        VehicleIssue::query()->firstOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'description' => $description,
            ],
            [
                'reported_by' => $reporter->id,
                'issue_types' => $types,
                'severity' => $severity,
                'status' => $status,
                'reported_at' => now()->subDays($daysAgo),
                'resolved_at' => $status === VehicleIssue::STATUS_RESOLVED ? now()->subDays(max(0, $daysAgo - 2)) : null,
            ]
        );
    }

    protected function maintenance(Vehicle $vehicle, array $maintenance): void
    {
        [$type, $description, $performedBy, $mileage, $cost, $serviceDaysAgo, $nextServiceDays, $nextMileage] = $maintenance;
        $serviceDate = now()->subDays($serviceDaysAgo)->toDateString();

        MaintenanceRecord::query()->firstOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'maintenance_type' => $type,
                'service_date' => $serviceDate,
            ],
            [
                'description' => $description,
                'performed_by' => $performedBy,
                'mileage_at_service' => $mileage,
                'cost' => $cost,
                'next_service_date' => $nextServiceDays ? now()->addDays($nextServiceDays)->toDateString() : null,
                'next_service_mileage' => $nextMileage,
            ]
        );
    }

    protected function expense(Vehicle $vehicle, User $manager, array $expense): void
    {
        [$type, $amount, $description, $daysAgo] = $expense;
        $expenseDate = now()->subDays($daysAgo)->toDateString();

        VehicleExpense::query()->firstOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'expense_type' => $type,
                'expense_date' => $expenseDate,
                'description' => $description,
            ],
            [
                'amount' => $amount,
                'currency' => 'GHS',
                'recorded_by' => $manager->id,
            ]
        );
    }
}
