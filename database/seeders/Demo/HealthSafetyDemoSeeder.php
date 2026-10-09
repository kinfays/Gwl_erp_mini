<?php

namespace Database\Seeders\Demo;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Models\HsPpeIssue;
use App\Models\HsPpeStockMovement;
use App\Models\HsPpeType;
use App\Models\HsSite;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\HealthSafety\EquipmentLabelService;
use App\Services\HealthSafety\FireExtinguisherService;
use App\Services\HealthSafety\FirstAidKitService;
use App\Services\HealthSafety\IncidentWorkflowService;
use App\Services\HealthSafety\PpeComplianceService;
use App\Services\HealthSafety\PpeIssueService;
use App\Services\HealthSafety\PpeSetupService;
use App\Services\HealthSafety\PpeStockService;
use App\Support\Audit;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Demo data for the Health & Safety module, for three regions.
 *
 * Not part of DatabaseSeeder. Run it after the normal seed (and after DemoDataSeeder if you want the Accra West staff
 * and districts it creates, which this seeder reuses; whatever is missing is added):
 *
 *   php artisan db:seed
 *   php artisan db:seed --class=Database\\Seeders\\Demo\\HealthSafetyDemoSeeder
 *
 * It needs GWL_HEALTH_SAFETY_MODULE_ENABLED=true (the notices it sends build links to module routes) and refuses to run in
 * production.
 *
 * Everything with derived state goes through the real services (IncidentWorkflowService, FireExtinguisherService,
 * FirstAidKitService, PpeStockService, PpeIssueService, PpeSetupService, EquipmentLabelService), never raw inserts: no
 * computed equipment state and no stock balance is ever written directly, so every state is one the application itself
 * produces. History is back-dated by moving the clock (Carbon::setTestNow) to each action's moment, restored in a finally.
 *
 * Idempotent: people, sites, vehicles, extinguishers and kits are keyed on staff id, name and asset code; the incident and
 * PPE blocks are each done in one transaction and recorded by an audit entry, so a second run adds nothing. Deterministic:
 * a fixed random seed, Ghanaian-sounding but fictional names, dates relative to now().
 */
class HealthSafetyDemoSeeder extends Seeder
{
    protected const RANDOM_SEED = 20261008;

    protected const PASSWORD = '12345';

    protected const EMAIL_DOMAIN = 'gwcl-demo.test';

    protected const SUPER_ADMIN_STAFF_ID = 'HSSA01';

    /** region key => name, regional-office location, districts, staff-id prefix */
    protected const REGIONS = [
        'accra' => ['name' => 'Accra West', 'office' => 'Accra West Regional Office', 'districts' => ['Darkuman', 'Sowutuom', 'Amasaman', 'Keneshie', 'Odorkor'], 'prefix' => 'HSA'],
        'ashanti' => ['name' => 'Ashanti', 'office' => 'Ashanti Regional Office', 'districts' => ['Kumasi Central', 'Suame', 'Bantama'], 'prefix' => 'HSK'],
        'western' => ['name' => 'Western', 'office' => 'Western Regional Office', 'districts' => ['Takoradi', 'Tarkwa', 'Axim'], 'prefix' => 'HSW'],
    ];

    protected const FIRST_NAMES_M = ['Kwame', 'Kofi', 'Kwabena', 'Yaw', 'Kojo', 'Kwesi', 'Nii', 'Nana', 'Ebo', 'Selasi', 'Edem', 'Fiifi', 'Kobina', 'Papa', 'Mawuli', 'Senyo', 'Kweku', 'Atta', 'Yao', 'Dela'];

    protected const FIRST_NAMES_F = ['Akosua', 'Abena', 'Ama', 'Esi', 'Efua', 'Adwoa', 'Yaa', 'Afia', 'Akua', 'Naa', 'Dzifa', 'Mawuena', 'Araba', 'Maame', 'Serwaa', 'Gifty', 'Mercy', 'Comfort', 'Patience', 'Adjoa'];

    protected const SURNAMES = ['Mensah', 'Owusu', 'Boateng', 'Asante', 'Appiah', 'Agyeman', 'Darko', 'Tetteh', 'Quaye', 'Amoah', 'Ofori', 'Sarpong', 'Nkrumah-Tawiah', 'Addo', 'Ankrah', 'Bonsu', 'Danquah', 'Kyei', 'Acheampong', 'Opoku', 'Gyamfi', 'Frimpong', 'Annan', 'Lamptey', 'Amponsah', 'Donkor', 'Ayivor', 'Dogbe', 'Kpodo', 'Eshun', 'Arthur', 'Baidoo', 'Essien', 'Nyarko', 'Yeboah'];

    protected const DEPARTMENTS = ['Administration', 'Operations', 'Distribution', 'Commercial', 'Finance'];

    protected const JOB_TITLES = ['Health and Safety Officer', 'Health and Safety Manager', 'Regional Chief Manager', 'District Manager', 'Plumber', 'Meter Reader', 'Engineer', 'Customer Service Officer', 'Accountant', 'Driver'];

    /** key => [label, kind, per region count] used for the sites of each region. */
    protected const PAY_POINT_SUFFIXES = ['Market Pay Point', 'Lorry Station Pay Point'];

    protected const PPE_TYPES = [
        // name, category, sizes (null = not sized), replacement months, unit
        'Safety boots' => ['foot', ['38', '39', '40', '41', '42', '43', '44', '45'], 12, 'pair'],
        'Safety helmet' => ['head', null, 36, 'each'],
        'Reflective vest' => ['body', ['S', 'M', 'L', 'XL'], 12, 'each'],
        'Work gloves' => ['hand', ['M', 'L', 'XL'], 6, 'pair'],
        'Safety glasses' => ['eye_face', null, 24, 'each'],
    ];

    /** job title => [PPE type => quantity] */
    protected const ENTITLEMENTS = [
        'Plumber' => ['Safety boots' => 1, 'Safety helmet' => 1, 'Reflective vest' => 2, 'Work gloves' => 1],
        'Meter Reader' => ['Safety boots' => 1, 'Reflective vest' => 1],
        'Engineer' => ['Safety boots' => 1, 'Safety helmet' => 1, 'Safety glasses' => 1, 'Reflective vest' => 2],
    ];

    /** The PPE profile of each field employee, in turn. Every compliance state appears. */
    protected const PPE_PROFILES = ['complete', 'complete', 'missing', 'overdue', 'short', 'replacement_due', 'replaced', 'complete', 'missing', 'short'];

    protected const EXTINGUISHER_STATES = [
        'ok' => 14, 'expiring' => 4, 'expired' => 4, 'service_overdue' => 3, 'hydro_overdue' => 2,
        'service_due_soon' => 3, 'check_failed' => 4, 'check_overdue' => 3, 'decommissioned' => 1, 'out_for_service' => 2,
    ];

    protected const KIT_STATES = [
        'ok' => 9, 'missing' => 2, 'item_expired' => 3, 'item_expiring' => 3, 'incomplete' => 3, 'check_failed' => 2, 'check_overdue' => 3,
    ];

    /** kit type => [item name, required quantity, has expiry] */
    protected const KIT_TEMPLATES = [
        'small' => [['Adhesive plasters', 20, false], ['Sterile gauze pads', 6, false], ['Antiseptic wipes', 10, true], ['Triangular bandage', 2, false], ['Disposable gloves', 4, false], ['First aid guide', 1, false]],
        'medium' => [['Adhesive plasters', 40, false], ['Sterile gauze pads', 12, false], ['Antiseptic wipes', 20, true], ['Triangular bandage', 4, false], ['Burn dressing', 3, true], ['Crepe bandage', 4, false], ['Disposable gloves', 8, false], ['Scissors', 1, false], ['First aid guide', 1, false]],
        'large' => [['Adhesive plasters', 100, false], ['Sterile gauze pads', 30, false], ['Antiseptic wipes', 50, true], ['Triangular bandage', 8, false], ['Burn dressing', 6, true], ['Crepe bandage', 10, false], ['Eye wash solution', 2, true], ['Disposable gloves', 20, false], ['Scissors', 2, false], ['Emergency blanket', 4, false], ['First aid guide', 2, false]],
        'vehicle' => [['Adhesive plasters', 20, false], ['Sterile gauze pads', 6, false], ['Antiseptic wipes', 10, true], ['Burn dressing', 2, true], ['Disposable gloves', 4, false], ['Emergency blanket', 2, false]],
    ];

    protected const DESCRIPTIONS = [
        'near_miss' => [
            'A ladder slipped on the wet floor while a colleague was changing a light bulb. Nobody was hurt.',
            'A pickup reversed very close to a meter reader on the roadside. The driver did not see him.',
            'A loose cable across the corridor nearly caused a fall in the customer hall.',
            'A pressurised pipe fitting came loose during testing and sprayed water close to the crew.',
            'A heavy valve cover was left leaning against the wall and fell over, missing a technician.',
        ],
        'injury' => [
            'A plumber cut his hand on a sharp pipe edge while cutting a galvanised line.',
            'A meter reader twisted her ankle stepping into an uncovered meter chamber.',
            'A technician strained his back lifting a pump motor without help.',
            'A cashier burned her hand on the kettle in the pantry.',
            'A labourer got dust in his eye while breaking concrete at the excavation.',
        ],
        'property_damage' => [
            'A company pickup hit the gate post while reversing out of the yard.',
            'A burst main flooded the ground floor store and damaged filing cabinets.',
            'A contractor excavator broke the service line on the main road.',
            'The generator housing was damaged when a trolley rolled into it.',
        ],
        'environmental' => [
            'About 50 litres of diesel leaked from the generator tank into the drain behind the depot.',
            'Chlorine tablets were spilled while being moved between stores. Area cleaned and aired.',
            'Sludge from a flushed main flowed into a roadside gutter before the crew could block it.',
        ],
        'incident' => [
            'Two customers argued loudly at the cash desk and one pushed a security guard.',
            'The office lift stopped between floors for about twenty minutes with two staff inside.',
            'A small electrical fire started in the accounts office extension board and was put out with an extinguisher.',
            'A stray dog entered the pay point and a customer was frightened. No bite.',
        ],
        'other' => [
            'The fire exit at the back of the office is blocked by stacked chairs.',
            'There is no first aid box at this pay point since the old one was taken away.',
            'The extinguisher near the stairs has been out of its bracket for some weeks.',
        ],
    ];

    protected const FIELD_PLACES = ['Burst main near the Odorkor market junction', 'Valve chamber on the main road behind the lorry station', 'Pipe laying site near the new estate', 'Meter reading route in the low-lying part of the district', 'Booster station fence line', 'Customer premises during a disconnection exercise'];

    protected const PAYPOINT_RAW = ['Tetteh Quarshie lorry park pay point', 'Roadside collection point near the market'];

    protected Randomizer $rng;

    protected Carbon $now;

    /** @var array<string, Region> */
    protected array $regions = [];

    /** @var array<string, array{office: District, districts: list<District>}> */
    protected array $locations = [];

    /** @var array<string, array<string, mixed>> region key => sites by role */
    protected array $sites = [];

    /** @var array<string, array<string, User>> region key => role key => user */
    protected array $logins = [];

    /** @var array<string, list<Employee>> */
    protected array $reporters = [];

    /** @var array<string, list<Employee>> region key => field employees (PPE staff) */
    protected array $fieldStaff = [];

    /** @var array<string, list<Vehicle>> */
    protected array $vehicles = [];

    /** @var array<string, Department> */
    protected array $departments = [];

    /** @var array<string, JobTitle> */
    protected array $jobTitles = [];

    protected ?User $superAdmin = null;

    /** @var array<string, int|string> */
    protected array $counts = [];

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    protected array $loginRows = [];

    protected array $usedNames = [];

    protected ?IncidentWorkflowService $incidents = null;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('The Health & Safety demo seeder never runs in production.');

            return;
        }

        if (! $this->prerequisitesMet()) {
            return;
        }

        $this->rng = new Randomizer(new Mt19937(self::RANDOM_SEED));
        $this->now = Carbon::now();
        $this->incidents = app(IncidentWorkflowService::class);

        // Invites (EmployeeObserver) and the incident notices are sent synchronously; keep any mail on this machine.
        $previousMailer = config('mail.default');
        config(['mail.default' => 'array']);

        $auditBefore = AuditLog::query()->count();

        try {
            $this->seedLookups();
            $this->seedLocations();
            $this->seedSites();
            $this->seedPeople();
            $this->seedVehicles();

            $this->phase('incidents', fn () => $this->seedIncidents());
            $this->seedEquipment();
            $this->phase('ppe', fn () => $this->seedPpe());
            $this->phase('labels', fn () => $this->printSomeLabels());
        } finally {
            Carbon::setTestNow();
            Auth::guard()->forgetUser();
            config(['mail.default' => $previousMailer]);
        }

        $this->counts['audit entries written'] = AuditLog::query()->count() - $auditBefore;

        $this->printSummary();
    }

    // ------------------------------------------------------------------ prerequisites

    protected function prerequisitesMet(): bool
    {
        foreach (['regions', 'districts', 'departments', 'job_titles', 'employees', 'users', 'roles', 'hs_incidents', 'hs_fire_extinguishers', 'hs_first_aid_kits', 'hs_ppe_types', 'hs_ppe_issues', 'vehicles'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->command?->error("Table [{$table}] is missing: run `php artisan migrate` first.");

                return false;
            }
        }

        if (! config('gwl.health_safety_module_enabled')) {
            $this->command?->error('Set GWL_HEALTH_SAFETY_MODULE_ENABLED=true first: the notices the workflow sends link to module routes that only exist while it is on.');

            return false;
        }

        $missing = collect(['super_admin', 'hs_officer', 'hs_manager', 'regional_chief_manager', 'district_manager', 'employee'])->diff(Role::query()->pluck('name'));

        if ($missing->isNotEmpty()) {
            $this->command?->error('Missing roles ('.$missing->join(', ').'): run `php artisan db:seed` first.');

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------ lookups, locations, sites

    protected function seedLookups(): void
    {
        foreach (self::DEPARTMENTS as $name) {
            $this->departments[$name] = Department::query()->whereRaw('LOWER(TRIM(department_name)) = ?', [Str::lower($name)])->first()
                ?? Department::query()->create(['department_name' => $name]);
        }

        foreach (self::JOB_TITLES as $name) {
            $this->jobTitles[$name] = JobTitle::query()->whereRaw('LOWER(TRIM(job_title_name)) = ?', [Str::lower($name)])->first()
                ?? JobTitle::query()->create(['job_title_name' => $name]);
        }
    }

    protected function seedLocations(): void
    {
        foreach (self::REGIONS as $key => $definition) {
            $region = Region::query()->whereRaw('LOWER(TRIM(region_name)) = ?', [Str::lower($definition['name'])])->first()
                ?? Region::query()->create(['region_name' => $definition['name']]);

            $existing = District::query()->where('region_id', $region->id)->get();

            // Employee::boot() derives location_type from the district NAME: "... regional office" means the regional office.
            $office = $existing->first(fn (District $district) => Str::contains(Str::lower($district->district_name), 'regional office'))
                ?? District::query()->create(['region_id' => $region->id, 'district_name' => $definition['office']]);

            $districts = [];

            foreach ($definition['districts'] as $name) {
                // One letter of tolerance, so "Keneshie" reuses DemoDataSeeder's (or HR's) "Kaneshie".
                $districts[] = $existing->first(fn (District $district) => ! Str::contains(Str::lower($district->district_name), ['regional office', 'head office'])
                    && levenshtein(Str::lower($district->district_name), Str::lower($name)) <= 1)
                    ?? District::query()->create(['region_id' => $region->id, 'district_name' => $name]);
            }

            $this->regions[$key] = $region;
            $this->locations[$key] = ['office' => $office, 'districts' => $districts];
        }
    }

    protected function seedSites(): void
    {
        foreach (self::REGIONS as $key => $definition) {
            $region = $this->regions[$key];
            $districts = $this->locations[$key]['districts'];

            $this->sites[$key] = [
                // A store is the regional office, which has no district (design 8.10.1).
                'office' => $this->site($definition['office'], HsSite::KIND_REGIONAL_OFFICE, $region, null, true),
                'district_offices' => [],
                'pay_points' => [],
                'depot' => $this->site($definition['name'].' Central Depot', HsSite::KIND_DEPOT, $region, $districts[0]),
            ];

            foreach (array_slice($districts, 0, 3) as $district) {
                $this->sites[$key]['district_offices'][] = $this->site($district->district_name.' District Office', HsSite::KIND_DISTRICT_OFFICE, $region, $district);
            }

            foreach (array_slice($districts, 0, 2) as $district) {
                foreach (self::PAY_POINT_SUFFIXES as $suffix) {
                    $this->sites[$key]['pay_points'][] = $this->site($district->district_name.' '.$suffix, HsSite::KIND_PAY_POINT, $region, $district);
                }
            }
        }

        $this->counts['sites'] = HsSite::query()->count();
    }

    protected function site(string $name, string $kind, Region $region, ?District $district, bool $store = false): HsSite
    {
        $site = HsSite::query()->where('name', $name)->where('region_id', $region->id)->first();

        if ($site) {
            return $site;
        }

        return HsSite::query()->create([
            'name' => $name,
            'kind' => $kind,
            'region_id' => $region->id,
            'district_id' => $district?->id,
            'is_active' => true,
            'is_ppe_store' => $store,
        ]);
    }

    // ------------------------------------------------------------------ people

    protected function seedPeople(): void
    {
        $roleIds = Role::query()->pluck('id', 'name');
        $kinds = [];

        foreach (self::REGIONS as $key => $definition) {
            $prefix = $definition['prefix'];
            $districts = $this->locations[$key]['districts'];
            $office = $this->locations[$key]['office'];

            $kinds[] = [$prefix.'001', $key, $office, 'Operations', 'Health and Safety Officer', 'Senior Staff', 'officer', ['hs_officer']];
            $kinds[] = [$prefix.'002', $key, $office, 'Operations', 'Health and Safety Manager', 'Management', 'manager', ['hs_manager']];
            $kinds[] = [$prefix.'003', $key, $office, 'Administration', 'Regional Chief Manager', 'Senior Management', 'chief', ['regional_chief_manager']];
            $kinds[] = [$prefix.'004', $key, $districts[0], 'Administration', 'District Manager', 'Management', 'district', ['district_manager']];

            // Field staff: who PPE is issued to, and who files most of the reports.
            foreach (range(1, 10) as $n) {
                $title = ['Plumber', 'Meter Reader', 'Engineer'][($n - 1) % 3];
                $dept = $title === 'Meter Reader' ? 'Commercial' : ($title === 'Plumber' ? 'Distribution' : 'Operations');
                $kinds[] = [sprintf('%s1%02d', $prefix, $n), $key, $districts[($n - 1) % count($districts)], $dept, $title, 'Junior Staff', 'field', []];
            }
        }

        // Five plain employees (no Health & Safety role): two in Accra West, two in Ashanti, one in Western.
        foreach ([['accra', 0], ['accra', 1], ['ashanti', 0], ['ashanti', 1], ['western', 0]] as $i => [$key, $districtIndex]) {
            $kinds[] = [self::REGIONS[$key]['prefix'].'2'.sprintf('%02d', $i + 1), $key, $this->locations[$key]['districts'][$districtIndex], 'Commercial', 'Customer Service Officer', 'Junior Staff', 'plain', []];
        }

        foreach ($kinds as [$staffId, $key, $district, $department, $title, $category, $role, $roles]) {
            $employee = $this->employee($staffId, $key, $district, $department, $title, $category);
            $user = $this->userFor($employee);

            $names = [...$roles, 'employee'];
            $user->roles()->syncWithoutDetaching($roleIds->only($names)->values()->all());
            $user = $user->fresh();

            if ($role === 'field') {
                $this->fieldStaff[$key][] = $employee;
                $this->reporters[$key][] = $employee;
            } elseif ($role === 'plain') {
                $this->reporters[$key][] = $employee;
                $this->loginRows[] = [$staffId, 'employee (plain)', $employee->full_name, $this->regions[$key]->region_name, self::PASSWORD];
            } else {
                $this->logins[$key][$role] = $user;
                $this->loginRows[] = [$staffId, implode(', ', $roles), $employee->full_name, $this->regions[$key]->region_name, self::PASSWORD];
            }

            if ($role === 'district') {
                $this->reporters[$key][] = $employee;
            }
        }

        $this->seedSuperAdmin($roleIds);
        $this->counts['logins'] = count($this->loginRows);
        $this->counts['employees (demo)'] = Employee::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->where('staff_id', 'like', 'HS%')->count();
    }

    protected function employee(string $staffId, string $key, District $district, string $department, string $title, string $category): Employee
    {
        $existing = Employee::query()->where('staff_id', $staffId)->first();

        if ($existing) {
            return $existing;
        }

        $female = $this->chance(0.45);
        $first = $this->pick($female ? self::FIRST_NAMES_F : self::FIRST_NAMES_M);
        $name = $this->uniqueName($first, $this->pick(self::SURNAMES));
        $born = Carbon::create($this->int(1974, 1995), $this->int(1, 12), $this->int(1, 28));
        $joined = Carbon::create($this->int(2010, 2022), $this->int(1, 12), $this->int(1, 28));
        $parts = explode(' ', $name);

        // EmployeeObserver::created() creates the login (default password, must change it, an invite that goes to the array mailer).
        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $name,
            'gender' => $female ? 'Female' : 'Male',
            'category' => $category,
            'email' => Str::lower(Str::ascii($parts[0].'.'.end($parts))).'.'.Str::lower($staffId).'@'.self::EMAIL_DOMAIN,
            'job_title_id' => $this->jobTitles[$title]->id,
            'department_id' => $this->departments[$department]->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'location_type' => Str::contains(Str::lower($district->district_name), 'regional office') ? 'Region' : 'District',
            'date_of_birth' => $born->toDateString(),
            'date_joined' => $joined->toDateString(),
            'present_appointment' => $joined->copy()->addYears($this->int(0, 3))->toDateString(),
            'is_active' => true,
        ]);
    }

    protected function userFor(Employee $employee): User
    {
        $find = fn () => User::query()->where('employee_id', $employee->id)->orWhere('staff_id', $employee->staff_id)->first();
        $user = $find();

        if (! $user) {
            $employee->touch();
            $user = $find();
        }

        if (! $user) {
            throw new RuntimeException("No login could be created for {$employee->staff_id}.");
        }

        if (! Hash::check(self::PASSWORD, $user->password) || $user->must_change_password) {
            $user->forceFill(['password' => Hash::make(self::PASSWORD), 'must_change_password' => false])->save();
        }

        return $user;
    }

    protected function seedSuperAdmin($roleIds): void
    {
        $user = User::query()->where('staff_id', self::SUPER_ADMIN_STAFF_ID)->first()
            ?? User::query()->create([
                'staff_id' => self::SUPER_ADMIN_STAFF_ID,
                'full_name' => 'Demo Super Administrator',
                'email' => 'demo.superadmin@'.self::EMAIL_DOMAIN,
                'password' => Hash::make(self::PASSWORD),
                'is_active' => true,
                'must_change_password' => false,
            ]);

        $user->roles()->syncWithoutDetaching($roleIds->only(['super_admin'])->values()->all());
        $this->superAdmin = $user->fresh();
        $this->loginRows[] = [self::SUPER_ADMIN_STAFF_ID, 'super_admin', $user->full_name, 'all', self::PASSWORD];
    }

    protected function seedVehicles(): void
    {
        foreach (self::REGIONS as $key => $definition) {
            foreach (range(1, 3) as $n) {
                $plate = sprintf('GV-%s%d-26', strtoupper($definition['prefix'][2]), 100 + $n);
                $this->vehicles[$key][] = Vehicle::query()->where('number_plate', $plate)->first() ?? Vehicle::query()->create([
                    'type' => array_key_first(Vehicle::TYPES),
                    'brand' => 'Toyota',
                    'model' => ['Hilux', 'Land Cruiser', 'Corolla'][$n - 1],
                    'color' => ['White', 'Blue', 'Silver'][$n - 1],
                    'number_plate' => $plate,
                    'year_purchased' => 2019 + $n,
                    'is_pool_car' => true,
                    'department_id' => $this->departments['Operations']->id,
                    'driver_type' => Vehicle::DRIVER_SELF_DRIVE,
                    'current_mileage' => 20000 * $n,
                    'maintenance_interval_km' => 5000,
                    'insurance_expiry_date' => $this->now->copy()->addMonths(5)->toDateString(),
                    'road_worthiness_expiry_date' => $this->now->copy()->addMonths(4)->toDateString(),
                    'status' => Vehicle::STATUS_ACTIVE,
                ]);
            }
        }
    }

    // ------------------------------------------------------------------ incidents

    protected function seedIncidents(): void
    {
        $lifecycles = [];

        foreach (['new_fresh' => 5, 'new_overdue' => 6, 'acknowledged' => 6, 'triaged' => 8, 'investigating' => 8, 'investigating_overdue' => 5,
            'pending_closure' => 5, 'returned' => 1, 'closed' => 9, 'closed_high' => 3, 'cancelled' => 4] as $name => $count) {
            array_push($lifecycles, ...array_fill(0, $count, $name));
        }

        $lifecycles = $this->rng->shuffleArray($lifecycles);
        $types = array_keys(HsIncident::TYPES);
        $contexts = array_keys(HsIncident::CONTEXTS);
        $anonymous = [4, 21, 38, 55];
        $regionKeys = array_keys(self::REGIONS);

        foreach ($lifecycles as $n => $lifecycle) {
            $regionKey = $regionKeys[$n % 3];
            $type = $types[($n * 5 + intdiv($n, 6)) % 6];
            $context = $contexts[($n + intdiv($n, 4)) % 4];

            $this->makeIncident($n, $lifecycle, $regionKey, $type, $context, [
                'anonymous' => in_array($n, $anonymous, true),
                'confidential' => ! in_array($n, $anonymous, true) && $n % 9 === 1,
                'on_behalf' => ! in_array($n, $anonymous, true) && $n % 13 === 3,
                'photos' => $n % 7 === 2 || $n === 21,
                'urgent' => $n % 11 === 5,
            ]);
        }

        $this->counts['incidents'] = HsIncident::query()->count();
    }

    /** @param  array<string, bool>  $flags */
    protected function makeIncident(int $n, string $lifecycle, string $regionKey, string $type, string $context, array $flags): void
    {
        $officer = $this->logins[$regionKey]['officer'];
        $chief = $this->logins[$regionKey]['chief'];
        $districtManager = $this->logins[$regionKey]['district'];
        $region = $this->regions[$regionKey];

        [$minDays, $maxDays] = match ($lifecycle) {
            'new_fresh' => [0, 0],
            'new_overdue' => [3, 20],
            'acknowledged' => [6, 40],
            'triaged' => [8, 50],
            'investigating' => [6, 13],
            'investigating_overdue' => [30, 110],
            'pending_closure' => [12, 60],
            'returned' => [20, 40],
            'closed' => [25, 118],
            'closed_high' => [35, 118],
            default => [5, 90],
        };

        $created = $lifecycle === 'new_fresh'
            ? $this->now->copy()->subHours($this->int(2, 20))
            : $this->now->copy()->subDays($this->int($minDays, $maxDays))->setTime($this->int(8, 16), $this->int(0, 59));

        // Who files it: a field or office employee of the region; an officer or district manager when it is recorded for someone.
        $pool = $this->reporters[$regionKey];
        $reporterEmployee = $pool[$n % count($pool)];
        $reporter = $this->userFor($reporterEmployee);

        if ($flags['on_behalf']) {
            $reporter = $n % 2 ? $officer : $districtManager;
        } elseif ($flags['confidential'] && $n % 2 === 0) {
            $reporter = $officer;   // an officer reporting in confidence: they are also the owner or closer, the classic leak
        }

        $districts = $this->locations[$regionKey]['districts'];
        $district = $districts[$n % count($districts)];
        $payPoints = $this->sites[$regionKey]['pay_points'];

        $data = [
            'incident_type' => $type,
            'other_type_text' => $type === 'other' ? 'Safety observation' : null,
            'context' => $context,
            'description' => $this->pick(self::DESCRIPTIONS[$type]),
            'first_aid' => $type === 'injury' ? $this->pick(['yes', 'yes', 'no']) : 'no_need',
            'occurred_on' => $created->copy()->subDays($this->int(0, 2))->toDateString(),
            'occurred_time' => $this->chance(0.7) ? sprintf('%02d:%02d', $this->int(6, 17), $this->int(0, 59)) : null,
            'no_witness' => $n % 3 === 0,
            'witness_name' => $n % 3 === 0 ? null : $this->uniqueName($this->pick(self::FIRST_NAMES_M), $this->pick(self::SURNAMES)),
            'witness_contact' => $n % 3 === 0 ? null : '024'.$this->int(1000000, 9999999),
            'is_confidential' => $flags['confidential'],
            'anonymous' => $flags['anonymous'],
            'is_urgent' => $flags['urgent'],
        ];

        match ($context) {
            HsIncident::CONTEXT_REGIONAL_OFFICE => $data['department_id'] = $this->departments[$this->pick(['Operations', 'Administration', 'Finance'])]->id,
            HsIncident::CONTEXT_DISTRICT_OFFICE => $data['district_id'] = $district->id,
            HsIncident::CONTEXT_PAY_POINT => [$data['district_id'], $data['site_id'], $data['site_name_raw']] = $n % 5 === 0
                ? [$districts[0]->id, null, $this->pick(self::PAYPOINT_RAW)]
                : [$payPoints[$n % count($payPoints)]->district_id, $payPoints[$n % count($payPoints)]->id, null],
            default => [$data['district_id'], $data['location_detail']] = [$district->id, $this->pick(self::FIELD_PLACES)],
        };

        if ($flags['on_behalf']) {
            $data['on_behalf'] = true;

            if ($n % 2) {
                $data['behalf_name'] = 'Contractor labourer ('.$this->pick(self::SURNAMES).')';   // no login
            } else {
                $data['behalf_employee_id'] = $reporterEmployee->id;
            }
        }

        $photos = $flags['photos'] ? $this->photos($n, $flags['anonymous'] ? 1 : $this->int(1, 2)) : [];

        $incident = $this->at($created, function () use ($reporter, $data, $photos) {
            $this->actAs($reporter);

            return $this->incidents->submit($reporter, $data, $photos);
        });

        $step = fn (Carbon $moment, User $actor, callable $do) => $this->at($moment, function () use ($actor, $do) {
            $this->actAs($actor);

            return $do();
        });

        $ack = $this->clamp($created->copy()->addHours($this->int(2, 20)));
        $severityLow = $this->pick(['low', 'medium']);
        $reload = fn () => $incident->fresh();
        $injury = $type === 'injury';

        $investigate = function (string $severity) use (&$incident, $step, $ack, $officer, $reload, $type) {
            $incident = $step($ack, $officer, fn () => $this->incidents->triage($reload(), $officer, ['severity' => $severity]));
            $incident = $step($this->clamp($ack->copy()->addDay()), $officer, fn () => $this->incidents->startInvestigation($reload(), $officer));
            $incident = $step($this->clamp($ack->copy()->addDays(3)), $officer, fn () => $this->incidents->saveInvestigation(
                $reload(),
                $officer,
                $this->pick(array_keys(HsIncident::ROOT_CAUSES)),
                'The team reviewed the site, spoke to the people involved and found the control that failed. Corrective actions are listed.'
            ));
        };

        $addPeople = function () use ($incident, $step, $ack, $officer, $injury, $regionKey, $reload) {
            if (! $injury) {
                return;
            }

            foreach (range(1, $this->int(1, 2)) as $k) {
                $person = $this->chance(0.5) ? $this->pick($this->reporters[$regionKey]) : null;
                $step($this->clamp($ack->copy()->addHours(2 + $k)), $officer, fn () => $this->incidents->addPerson($reload(), $officer, [
                    'staff_id' => $person?->staff_id,
                    'name_raw' => $person ? null : $this->uniqueName($this->pick(self::FIRST_NAMES_M), $this->pick(self::SURNAMES)),
                    'person_type' => $person ? 'staff' : $this->pick(['contractor', 'visitor', 'public']),
                    'injury_type' => $this->pick(['Laceration', 'Sprain', 'Burn', 'Bruise', 'Eye irritation', 'Back strain']),
                    'body_part' => $this->pick(['Hand', 'Foot', 'Back', 'Eye', 'Leg', 'Arm']),
                    'treatment' => $this->pick(['first_aid', 'first_aid', 'clinic', 'hospital']),
                    'first_aider_name' => $this->uniqueName($this->pick(self::FIRST_NAMES_F), $this->pick(self::SURNAMES)),
                    'lost_time_days' => $this->pick([0, 0, 1, 2, 5, 9]),
                ]));
            }
        };

        // Actions: assigned to staff of the region; some completed and verified, some still open, some overdue.
        $addActions = function (string $mode) use (&$incident, $step, $created, $officer, $regionKey, $reload) {
            $count = $mode === 'none' ? 0 : $this->int(1, 2);
            $assignees = $this->reporters[$regionKey];

            foreach (range(1, $count) as $k) {
                $assignee = $assignees[($k + $created->day) % count($assignees)];
                $due = match ($mode) {
                    'overdue' => $created->copy()->addDays(7 + $k),
                    'future' => $this->now->copy()->addDays($this->int(3, 21)),
                    default => $created->copy()->addDays(10 + 3 * $k),
                };
                $at = $this->clamp($created->copy()->addDays(1));

                $action = $step($at, $officer, fn () => $this->incidents->createAction($reload(), $officer, [
                    'description' => $this->pick(['Repair or replace the damaged equipment', 'Brief the crew on the safe method and record attendance', 'Fence off and mark the hazard', 'Fit a guard on the exposed part', 'Review the work method with the district manager']),
                    'assigned_to_employee_id' => $assignee->id,
                    'due_on' => $due->toDateString(),
                ]));

                if (in_array($mode, ['done', 'verified'], true) && $due->lt($this->now)) {
                    $doneAt = $this->clamp($due->copy()->subDays(2));
                    $assigneeUser = $this->userFor($assignee);
                    $action = $step($doneAt, $assigneeUser, fn () => $this->incidents->completeAction(HsIncidentAction::query()->findOrFail($action->id), $assigneeUser, 'Done and photographed.'));

                    if ($mode === 'verified') {
                        $step($this->clamp($doneAt->copy()->addDay()), $officer, fn () => $this->incidents->verifyAction(HsIncidentAction::query()->findOrFail($action->id), $officer));
                    }
                }
            }
        };

        switch ($lifecycle) {
            case 'new_fresh':
            case 'new_overdue':
                break;

            case 'acknowledged':
                $step($ack, $officer, fn () => $this->incidents->acknowledge($reload(), $officer));
                $addPeople();
                break;

            case 'triaged':
                $step($ack, $officer, fn () => $this->incidents->triage($reload(), $officer, ['severity' => $severityLow]));
                $addPeople();
                $addActions('future');
                break;

            case 'investigating':
                $investigate($this->pick(['medium', 'high']));
                $addPeople();
                // A rating raised later is a timeline note, and tells the chief manager and the Health & Safety Manager.
                if ($n % 2 === 0) {
                    $step($this->clamp($ack->copy()->addDays(4)), $officer, fn () => $this->incidents->triage($reload(), $officer, ['severity' => 'high']));
                }
                $addActions('future');
                break;

            case 'investigating_overdue':
                $investigate($this->pick(['low', 'medium']));
                $addPeople();
                $addActions('overdue');
                break;

            case 'pending_closure':
            case 'returned':
                $investigate($this->pick(['high', 'critical']));
                $addPeople();
                $addActions('verified');
                $sentAt = $this->clamp($ack->copy()->addDays(6));
                $step($sentAt, $officer, fn () => $this->incidents->sendForApproval($reload(), $officer, 'Findings are complete and the corrective actions are in place.'));

                if ($lifecycle === 'returned') {
                    $step($this->clamp($sentAt->copy()->addDay()), $chief, fn () => $this->incidents->returnForRework($reload(), $chief, 'Add the training record for the crew before this is closed.'));
                    $step($this->clamp($sentAt->copy()->addDays(4)), $officer, fn () => $this->incidents->sendForApproval($reload(), $officer, 'Training record attached to the file; all actions verified.'));
                }
                break;

            case 'closed':
                $investigate($severityLow);
                $addPeople();
                $addActions('verified');
                $closeAt = $this->clamp($ack->copy()->addDays($this->int(6, 14)));
                $incident = $step($closeAt, $officer, fn () => $this->incidents->close($reload(), $officer, 'Thank you for reporting. The hazard has been fixed and the crew briefed.'));

                if ($n % 5 === 0) {
                    $step($this->clamp($closeAt->copy()->addDays(3)), $officer, fn () => $this->incidents->reopen($reload(), $officer, 'The district office reported the same fault again.'));
                }
                break;

            case 'closed_high':
                $investigate($this->pick(['high', 'critical']));
                $addPeople();
                $addActions('verified');
                $sentAt = $this->clamp($ack->copy()->addDays(8));
                $step($sentAt, $officer, fn () => $this->incidents->sendForApproval($reload(), $officer, 'Investigation complete; actions verified.'));
                $step($this->clamp($sentAt->copy()->addDays(2)), $chief, fn () => $this->incidents->approveAndClose($reload(), $chief, 'Approved. Thank you for the thorough follow-up.'));
                break;

            case 'cancelled':
                $step($this->clamp($ack->copy()->addDay()), $officer, fn () => $this->incidents->cancel($reload(), $officer, $this->pick(['Filed twice by mistake; the other report is kept.', 'Not a Health & Safety matter; passed to Operations.'])));
                break;
        }
    }

    /** @return list<UploadedFile> small generated JPEGs; nothing real */
    protected function photos(int $seed, int $count): array
    {
        $files = [];

        foreach (range(1, $count) as $k) {
            $image = imagecreatetruecolor(640, 480);
            imagefill($image, 0, 0, imagecolorallocate($image, 60 + ($seed * 7) % 120, 90 + ($seed * 13) % 100, 130 + ($seed * 3) % 80));
            imagestring($image, 5, 24, 24, 'Demo photo '.$seed.'-'.$k, imagecolorallocate($image, 255, 255, 255));
            imagefilledrectangle($image, 120, 160, 520, 400, imagecolorallocate($image, 230, 230, 230));

            $path = tempnam(sys_get_temp_dir(), 'hsdemo').'.jpg';
            imagejpeg($image, $path, 80);
            imagedestroy($image);

            $files[] = new UploadedFile($path, 'IMG_'.sprintf('%04d', $seed * 10 + $k).'.jpg', 'image/jpeg', null, true);
        }

        return $files;
    }

    // ------------------------------------------------------------------ equipment

    protected function seedEquipment(): void
    {
        $this->seedKitTemplates();

        $extinguishers = app(FireExtinguisherService::class);
        $kits = app(FirstAidKitService::class);

        $planE = [];

        foreach (self::EXTINGUISHER_STATES as $state => $count) {
            array_push($planE, ...array_fill(0, $count, $state));
        }

        $planK = [];

        foreach (self::KIT_STATES as $state => $count) {
            array_push($planK, ...array_fill(0, $count, $state));
        }

        $regionKeys = array_keys(self::REGIONS);

        foreach ($this->rng->shuffleArray($planE) as $i => $state) {
            $this->makeExtinguisher($extinguishers, $i, $regionKeys[$i % 3], $state);
        }

        foreach ($this->rng->shuffleArray($planK) as $i => $state) {
            $this->makeKit($kits, $i, $regionKeys[$i % 3], $state);
        }

        $this->counts['extinguishers'] = HsFireExtinguisher::query()->count();
        $this->counts['first aid kits'] = HsFirstAidKit::query()->count();
    }

    protected function seedKitTemplates(): void
    {
        $service = app(FirstAidKitService::class);
        $this->actAs($this->logins['accra']['manager']);

        foreach (self::KIT_TEMPLATES as $type => $items) {
            if (\App\Models\HsFirstAidItemTemplate::query()->where('kit_type', $type)->exists()) {
                continue;
            }

            $service->saveTemplates($this->logins['accra']['manager'], $type, array_map(fn ($row) => ['item_name' => $row[0], 'required_qty' => $row[1], 'has_expiry' => $row[2]], $items));
        }
    }

    /** Where item $i of a region goes: a site, or (every sixth) a vehicle. @return array<string, mixed> */
    protected function place(string $regionKey, int $i, string $what): array
    {
        $sites = [...$this->sites[$regionKey]['district_offices'], $this->sites[$regionKey]['office'], $this->sites[$regionKey]['depot'], ...$this->sites[$regionKey]['pay_points']];

        if ($i % 6 === 5) {
            $vehicle = $this->vehicles[$regionKey][$i % 3];

            return ['vehicle_id' => $vehicle->id, 'region_id' => $this->regions[$regionKey]->id, 'district_id' => $this->locations[$regionKey]['districts'][0]->id, 'location_detail' => 'Behind the driver seat'];
        }

        $site = $sites[intdiv($i, 3) % count($sites)];

        return ['site_id' => $site->id, 'location_detail' => $this->pick(['By the entrance', 'Reception desk', 'Store room door', 'Corridor, first floor', 'Next to the cashier', 'Workshop wall'])];
    }

    protected function makeExtinguisher(FireExtinguisherService $service, int $i, string $regionKey, string $state): void
    {
        $code = sprintf('DEMO-FE-%03d', $i + 1);

        if (HsFireExtinguisher::query()->where('asset_code', $code)->exists()) {
            return;
        }

        $officer = $this->logins[$regionKey]['officer'];
        $responsible = $this->pick($this->reporters[$regionKey]);
        $createdAt = $this->now->copy()->subDays($state === 'check_overdue' ? $this->int(100, 120) : $this->int(150, 210));
        $d = fn (int $days) => $this->now->copy()->addDays($days)->toDateString();

        $dates = ['expiry_date' => $d($this->int(250, 800)), 'next_service_due' => $d($this->int(120, 320)), 'next_hydro_test_due' => $d($this->int(500, 1500))];

        match ($state) {
            'expiring' => $dates['expiry_date'] = $d($this->int(8, 50)),
            'expired' => $dates['expiry_date'] = $d(-$this->int(5, 60)),
            'service_overdue' => $dates['next_service_due'] = $d(-$this->int(5, 40)),
            'hydro_overdue' => $dates['next_hydro_test_due'] = $d(-$this->int(5, 90)),
            'service_due_soon' => $dates['next_service_due'] = $d($this->int(10, 50)),
            default => null,
        };

        $unit = $this->at($createdAt, function () use ($service, $officer, $code, $i, $regionKey, $responsible, $dates) {
            $this->actAs($officer);

            return $service->create($officer, [
                'asset_code' => $code,
                'serial_number' => sprintf('SN%06d', 420000 + $i * 37),
                'extinguisher_type' => $this->pick(array_keys(HsFireExtinguisher::TYPES)),
                'capacity' => $this->pick(['2 kg', '4.5 kg', '6 kg', '9 kg']),
                'manufacturer' => $this->pick(['Kidde', 'Angus Fire', 'Total Fire']),
                'responsible_employee_id' => $responsible->id,
                ...$this->place($regionKey, $i, 'extinguisher'),
                ...$dates,
            ]);
        });

        $check = function (int $daysAgo, bool $pass) use ($service, $unit, $officer) {
            $moment = $this->now->copy()->subDays($daysAgo)->setTime(10, $this->int(0, 59));

            $this->at($moment, function () use ($service, $unit, $officer, $pass, $moment) {
                $this->actAs($officer);
                $service->recordCheck($unit->fresh(), $officer, [
                    'checked_on' => $moment->toDateString(),
                    'in_place' => true, 'accessible' => true, 'seal_intact' => true, 'pressure_ok' => $pass, 'no_damage' => true, 'signage_ok' => true,
                    'notes' => $pass ? null : 'The pressure gauge is in the red.',
                ]);
            });
        };

        match ($state) {
            'check_overdue' => [$check($this->int(48, 70), true)],
            'check_failed' => [$check($this->int(35, 50), true), $check($this->int(1, 8), false)],
            'decommissioned' => null,
            default => [$check($this->int(34, 60), true), $check($this->int(1, 20), true)],
        };

        if (in_array($state, ['ok', 'expiring', 'service_due_soon'], true) && $i % 2 === 0) {
            $servicedOn = $this->now->copy()->subDays($this->int(30, 120));

            $this->at($servicedOn->copy()->setTime(14, 0), function () use ($service, $unit, $officer, $servicedOn, $dates) {
                $this->actAs($officer);
                $service->recordService($unit->fresh(), $officer, [
                    'serviced_on' => $servicedOn->toDateString(),
                    'service_type' => 'inspection',
                    'vendor' => 'Gold Coast Fire Safety Ltd',
                    // The unit's own dates are kept: a service would otherwise reset them.
                    'next_service_due' => $dates['next_service_due'],
                    'notes' => 'Annual inspection; no fault found.',
                ]);
            });
        }

        match ($state) {
            'decommissioned' => $this->at($this->now->copy()->subDays(9), function () use ($service, $unit, $officer) {
                $this->actAs($officer);
                $service->decommission($unit->fresh(), $officer, 'Discharged and condemned after a pressure test failure.');
            }),
            'out_for_service' => $this->at($this->now->copy()->subDays(4), function () use ($service, $unit, $officer) {
                $this->actAs($officer);
                $service->changeStatus($unit->fresh(), $officer, HsFireExtinguisher::STATUS_OUT_FOR_SERVICE);
            }),
            default => null,
        };
    }

    protected function makeKit(FirstAidKitService $service, int $i, string $regionKey, string $state): void
    {
        $code = sprintf('DEMO-FK-%03d', $i + 1);

        if (HsFirstAidKit::query()->where('asset_code', $code)->exists()) {
            return;
        }

        $officer = $this->logins[$regionKey]['officer'];
        $responsible = $this->pick($this->reporters[$regionKey]);
        $place = $this->place($regionKey, $i + 3, 'kit');
        $type = isset($place['vehicle_id']) ? 'vehicle' : $this->pick(['small', 'medium', 'medium', 'large']);
        $createdAt = $this->now->copy()->subDays($state === 'check_overdue' ? $this->int(100, 120) : $this->int(70, 150));

        $kit = $this->at($createdAt, function () use ($service, $officer, $code, $type, $place, $responsible) {
            $this->actAs($officer);

            return $service->create($officer, ['asset_code' => $code, 'kit_type' => $type, 'responsible_employee_id' => $responsible->id, ...$place]);
        });

        $far = fn () => $this->now->copy()->addDays($this->int(150, 600))->toDateString();

        // What the kit holds at a check: every item complete and far from expiry, unless the state needs otherwise.
        $holdings = function (?string $trouble = null) use ($kit, $far) {
            $updates = [];
            $troubled = false;

            foreach ($kit->fresh()->items as $item) {
                $update = ['current_qty' => $item->required_qty];

                if ($item->has_expiry) {
                    $update['expiry_date'] = $far();

                    if (! $troubled && $trouble === 'expired') {
                        $update['expiry_date'] = $this->now->copy()->subDays($this->int(3, 40))->toDateString();
                        $troubled = true;
                    } elseif (! $troubled && $trouble === 'expiring') {
                        $update['expiry_date'] = $this->now->copy()->addDays($this->int(8, 45))->toDateString();
                        $troubled = true;
                    }
                }

                if (! $troubled && $trouble === 'short') {
                    $update['current_qty'] = max(0, $item->required_qty - 2);
                    $troubled = true;
                }

                $updates[$item->id] = $update;
            }

            return $updates;
        };

        $check = function (int $daysAgo, ?string $trouble, ?string $notes = null, ?int $clockDaysAgo = null) use ($service, $kit, $officer, $holdings) {
            $checkedOn = $this->now->copy()->subDays($daysAgo)->setTime(11, $this->int(0, 59));
            $clock = $this->now->copy()->subDays($clockDaysAgo ?? $daysAgo)->setTime(11, 30);

            $this->at($clock, function () use ($service, $kit, $officer, $holdings, $trouble, $notes, $checkedOn) {
                $this->actAs($officer);
                $service->recordCheck($kit->fresh(), $officer, ['checked_on' => $checkedOn->toDateString(), 'restocked' => $trouble === null, 'notes' => $notes], $holdings($trouble));
            });
        };

        match ($state) {
            'ok' => [$check($this->int(30, 60), null), $check($this->int(1, 20), null)],
            'check_overdue' => $check($this->int(45, 70), null),
            'item_expiring' => $check($this->int(1, 20), 'expiring'),
            'item_expired' => $check($this->int(1, 15), 'expired', 'An item is past its expiry date and must be replaced.'),
            'incomplete' => $check($this->int(1, 15), 'short', 'Some items are below the quantity the kit should hold.'),
            // The latest check failed, but a check recorded afterwards for an EARLIER date restocked everything: the state is
            // then the failed latest check alone (a late entry never replaces the latest result).
            'check_failed' => [$check(6, 'short', 'Gauze and wipes are short.', 6), $check(12, null, null, 3)],
            'missing' => [$check($this->int(10, 30), null), $this->at($this->now->copy()->subDays(3), function () use ($service, $kit, $officer) {
                $this->actAs($officer);
                $service->setMissing($kit->fresh(), $officer, true);
            })],
            default => null,
        };
    }

    protected function printSomeLabels(): void
    {
        $labels = app(EquipmentLabelService::class);

        foreach (array_keys(self::REGIONS) as $key) {
            $officer = $this->logins[$key]['officer'];
            $this->actAs($officer);

            foreach (['extinguisher' => HsFireExtinguisher::class, 'kit' => HsFirstAidKit::class] as $type => $model) {
                $ids = $model::query()->where('region_id', $this->regions[$key]->id)->where('asset_code', 'like', 'DEMO-%')->whereNull('label_printed_at')
                    ->where('status', '!=', 'decommissioned')->orderBy('id')->limit(3)->pluck('id')->all();

                if ($ids !== []) {
                    $labels->print($officer, $type, $ids);
                }
            }
        }

        $this->counts['items with a printed label'] = HsFireExtinguisher::query()->whereNotNull('label_printed_at')->count() + HsFirstAidKit::query()->whereNotNull('label_printed_at')->count();
    }

    // ------------------------------------------------------------------ PPE

    protected function seedPpe(): void
    {
        $setup = app(PpeSetupService::class);
        $stock = app(PpeStockService::class);
        $issues = app(PpeIssueService::class);
        $manager = $this->logins['accra']['manager'];   // hs_manager: sees and sets up every region
        $this->actAs($manager);

        // Types, entitlements and reorder levels.
        $types = [];

        foreach (self::PPE_TYPES as $name => [$category, $sizes, $months, $unit]) {
            $types[$name] = HsPpeType::query()->where('name', $name)->first()
                ?? $setup->saveType($manager, null, ['name' => $name, 'category' => $category, 'has_sizes' => $sizes !== null, 'sizes' => $sizes ?? [], 'replacement_months' => $months, 'has_expiry' => false, 'unit' => $unit, 'is_active' => true]);
        }

        foreach (self::ENTITLEMENTS as $title => $lines) {
            $setup->saveEntitlements($manager, $this->jobTitles[$title], collect($lines)->mapWithKeys(fn ($quantity, $name) => [$types[$name]->id => $quantity])->all());
        }

        $sizeFor = fn (HsPpeType $type, int $n) => $type->has_sizes ? $type->sizeList()[$n % count($type->sizeList())] : null;

        foreach (array_keys(self::REGIONS) as $regionIndex => $key) {
            $officer = $this->logins[$key]['officer'];
            $store = $this->sites[$key]['office'];
            $this->actAs($officer);

            // Opening stock as receipts, 100 days ago: more than the issues below can take, so no balance goes below zero.
            $this->at($this->now->copy()->subDays(100), function () use ($stock, $officer, $store, $types) {
                foreach ($types as $type) {
                    foreach ($type->has_sizes ? $type->sizeList() : [null] as $size) {
                        $stock->receive($officer, $store, $type, $size, 14, 'GRN-'.strtoupper(Str::random(5)), 'Opening stock (demo)');
                    }
                }
            });

            $setup->saveReorderLevels($officer, [
                ['site_id' => $store->id, 'ppe_type_id' => $types['Work gloves']->id, 'level' => 45],   // above its total: low
                ['site_id' => $store->id, 'ppe_type_id' => $types['Safety boots']->id, 'level' => 20],
                ['site_id' => $store->id, 'ppe_type_id' => $types['Reflective vest']->id, 'level' => 12],
                ['site_id' => $store->id, 'ppe_type_id' => $types['Safety helmet']->id, 'level' => 8],
            ]);

            $this->at($this->now->copy()->subDays(60), function () use ($stock, $officer, $store, $types) {
                $stock->adjust($officer, $store, $types['Safety helmet'], null, -1, 'Stock count: one helmet could not be found.');
                $stock->writeOff($officer, $store, $types['Work gloves'], 'M', 2, 'Damaged by water in the store.');
            });

            if ($regionIndex === 1) {
                $this->at($this->now->copy()->subDays(30), function () use ($stock, $manager, $types) {
                    $this->actAs($manager);
                    $from = $this->sites['accra']['office'];
                    $to = $this->sites['ashanti']['office'];
                    $stock->transfer($manager, $from, $to, $types['Safety helmet'], null, 3, 'Top-up for Ashanti (demo)');
                });
            }

            // What each field employee holds, by profile.
            foreach ($this->fieldStaff[$key] as $k => $employee) {
                $title = $employee->jobTitle?->job_title_name ?? JobTitle::query()->find($employee->job_title_id)?->job_title_name;
                $lines = self::ENTITLEMENTS[$title] ?? [];
                $profile = self::PPE_PROFILES[$k % count(self::PPE_PROFILES)];
                $this->actAs($officer);

                $issue = function (string $name, int $quantity, ?int $daysAgo, bool $historic, array $extra = [], array $closings = []) use ($issues, $officer, $employee, $types, $store, $sizeFor, $k) {
                    $type = $types[$name];
                    $data = ['employee_id' => $employee->id, 'ppe_type_id' => $type->id, 'size' => $sizeFor($type, $k + $employee->id), 'quantity' => $quantity, ...$extra];

                    if ($historic) {
                        $data += ['is_historic' => true, 'issued_on' => $this->now->copy()->subDays($daysAgo)->toDateString()];

                        return $issues->issue($officer, $data, $closings);
                    }

                    $data += ['store_id' => $store->id];

                    return $this->at($this->now->copy()->subDays($daysAgo)->setTime(10, 0), function () use ($issues, $officer, $data, $closings) {
                        $this->actAs($officer);

                        return $issues->issue($officer, $data, $closings);
                    });
                };

                switch ($profile) {
                    case 'complete':
                        foreach ($lines as $name => $quantity) {
                            $issue($name, $quantity, $this->int(10, 60), false);
                        }
                        break;

                    case 'missing':
                        break;

                    case 'overdue':
                        // The boots were issued long ago and are past their date; everything else is in order.
                        foreach ($lines as $name => $quantity) {
                            $name === 'Safety boots' ? $issue($name, $quantity, 30 * 31, true) : $issue($name, $quantity, $this->int(10, 60), false);
                        }
                        break;

                    case 'short':
                        foreach ($lines as $name => $quantity) {
                            $issue($name, $quantity > 1 ? $quantity - 1 : $quantity, $this->int(10, 60), false);
                        }
                        break;

                    case 'replacement_due':
                        foreach ($lines as $name => $quantity) {
                            // Boots issued 11 months ago, due in about a month: inside the warning window.
                            $name === 'Safety boots' ? $issue($name, $quantity, 335, true) : $issue($name, $quantity, $this->int(10, 60), false);
                        }
                        break;

                    case 'replaced':
                        foreach ($lines as $name => $quantity) {
                            if ($name === 'Safety boots') {
                                $old = $issue($name, $quantity, 14 * 30, true);
                                $issue($name, $quantity, 12, false, [], [['issue_id' => $old->id, 'outcome' => 'worn_out', 'note' => 'Soles worn through.']]);
                            } else {
                                $issue($name, $quantity, $this->int(10, 60), false);
                            }
                        }
                        break;
                }
            }
        }

        $this->counts['ppe stock lines'] = HsPpeStockMovement::query()->count();
        $this->counts['ppe issues'] = HsPpeIssue::query()->count();
    }

    // ------------------------------------------------------------------ helpers

    /** Run one block of demo work once, in one transaction, and remember that it was done. */
    protected function phase(string $name, callable $work): void
    {
        $marker = 'health_safety.demo_seeded';

        if (AuditLog::query()->where('action', $marker)->where('metadata', 'like', '%"phase":"'.$name.'"%')->exists()) {
            $this->command?->line("Phase [{$name}] was already seeded: nothing added.");

            return;
        }

        DB::transaction(function () use ($work, $name, $marker) {
            $work();

            $this->actAs($this->superAdmin);
            Audit::log($marker, 'health_safety', 'demo', null, ['phase' => $name]);
        });
    }

    /** Run $callback with the clock set to $moment (timestamps, "today" in the services' date rules). */
    protected function at(Carbon $moment, callable $callback): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($moment->gte($this->now) ? $this->now->copy()->subMinute() : $moment);

        try {
            return $callback();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    protected function clamp(Carbon $moment): Carbon
    {
        $ceiling = $this->now->copy()->subMinutes(2);

        return $moment->gt($ceiling) ? $ceiling : $moment;
    }

    protected function actAs(?User $user): void
    {
        $user ? Auth::guard()->setUser($user) : Auth::guard()->forgetUser();
    }

    protected function uniqueName(string $first, string $surname): string
    {
        $name = $first.' '.$surname;

        for ($suffix = 2; isset($this->usedNames[$name]); $suffix++) {
            $name = $first.' '.$surname.'-'.$this->pick(self::SURNAMES);

            if ($suffix > 6) {
                $name = $first.' '.$surname.' '.chr(64 + $suffix);
            }
        }

        $this->usedNames[$name] = true;

        return $name;
    }

    protected function int(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    protected function chance(float $probability): bool
    {
        return $this->rng->getInt(1, 10000) <= (int) round($probability * 10000);
    }

    protected function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items[$this->rng->getInt(0, count($items) - 1)];
    }

    protected function printSummary(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->newLine();
        $this->command->info('Demo logins (password for all: '.self::PASSWORD.')');
        $this->command->table(['Staff ID', 'Role', 'Name', 'Region', 'Password'], $this->loginRows);

        $incidentRows = [];

        foreach (HsIncident::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->orderBy('status')->get() as $row) {
            $incidentRows[] = ['incidents: '.$row->status, $row->total];
        }

        $extinguisherStates = [];
        HsFireExtinguisher::query()->get()->each(function (HsFireExtinguisher $unit) use (&$extinguisherStates) {
            $state = $unit->state() ?? 'not evaluated ('.$unit->status.')';
            $extinguisherStates[$state] = ($extinguisherStates[$state] ?? 0) + 1;
        });
        $kitStates = [];
        HsFirstAidKit::query()->with('items')->get()->each(function (HsFirstAidKit $kit) use (&$kitStates) {
            $state = $kit->state() ?? 'not evaluated';
            $kitStates[$state] = ($kitStates[$state] ?? 0) + 1;
        });

        $summary = $this->superAdmin ? app(PpeComplianceService::class)->summary($this->superAdmin) : null;

        $rows = [
            ...collect($this->counts)->map(fn ($value, $label) => [$label, $value])->values()->all(),
            ...$incidentRows,
            ['incidents: confidential', HsIncident::query()->where('is_confidential', true)->count()],
            ['incidents: anonymous', HsIncident::query()->where('is_anonymous', true)->count()],
            ['incidents: recorded on behalf', HsIncident::query()->whereNotNull('recorded_by_user_id')->count()],
            ['incident actions (open / done / verified)', implode(' / ', [HsIncidentAction::query()->where('status', 'open')->count(), HsIncidentAction::query()->where('status', 'done')->count(), HsIncidentAction::query()->where('status', 'verified')->count()])],
            ...collect($extinguisherStates)->sortKeys()->map(fn ($value, $state) => ['extinguishers: '.$state, $value])->values()->all(),
            ...collect($kitStates)->sortKeys()->map(fn ($value, $state) => ['kits: '.$state, $value])->values()->all(),
            ...($summary ? collect($summary['by_state'])->map(fn ($value, $state) => ['PPE compliance: '.$state, $value])->values()->all() : []),
        ];

        $this->command->info('Record counts');
        $this->command->table(['What', 'Count'], $rows);
    }
}
