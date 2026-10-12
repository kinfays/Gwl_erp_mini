<?php

namespace Database\Seeders\Demo;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\GwExpense;
use App\Models\GwOpeningBalance;
use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Models\GwSupplierPayment;
use App\Models\JobTitle;
use App\Models\MileageLog;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Services\GWater\GwaterProfitService;
use App\Services\GWater\GwaterStockService;
use App\Services\GWater\GwaterSummaryService;
use App\Services\GWater\GwaterWorkflowService;
use App\Services\GWater\Money;
use Database\Seeders\GwaterRolePermissionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Explorable G-Water data for the Accra West region: about ten weeks of bottle and sachet water, ending yesterday.
 *
 * Not part of DatabaseSeeder. Run it after the normal seed and after the G-Water migrations (and, if you want its staff
 * to sit next to the other demo people, after DemoDataSeeder), with GWL_GWATER_MODULE_ENABLED=true to see the screens:
 *
 *   php artisan migrate
 *   php artisan db:seed
 *   php artisan db:seed --class=Database\\Seeders\\Demo\\GwaterDemoSeeder
 *
 * What you get: the location's own price list (a price rise part-way through), an opening balance, a collection trip every
 * week (some with a Transport vehicle, mileage entry and fuel expense linked, some with fuel typed in G-Water, some with fuel
 * a member of staff paid out of pocket, a few of which are already paid back), daily sales Monday to Saturday (about one in seven on credit to named customers who pay in full, in part or not yet), a few damages
 * and other expenses, payment requests to G Water (most paid, one recent, one overdue), one voided sale, and a low-stock
 * limit for sachet water that makes the Overview show a flag. Everything goes through GwaterWorkflowService acting as the demo G-Water
 * users, so stock, prices, audit rows and reimbursement rules are exactly what the application produces; History is
 * back-dated by moving the clock (Carbon::setTestNow) to each day, restored in a finally.
 *
 * Idempotent: if Accra West already has demo trips (waybills starting "GW-") or any opening balance, nothing more is added. Deterministic: a fixed
 * random seed and dates relative to today. Refuses to run in production. Every demo login has the password 12345.
 */
class GwaterDemoSeeder extends Seeder
{
    protected const REGION_NAME = 'Accra West';

    protected const OFFICE_NAME = 'Accra West Regional Office';

    protected const DISTRICTS = ['Darkuman', 'Sowutuom', 'Amasaman', 'Keneshie', 'Odorkor'];

    protected const PASSWORD = '12345';

    protected const EMAIL_DOMAIN = 'gwcl-demo.test';

    protected const RANDOM_SEED = 20261011;

    protected const WAYBILL_PREFIX = 'GW-';

    protected const VEHICLE_PLATE = 'GW 4821-26';

    /** Days of history before yesterday. */
    protected const DAYS = 70;

    /** staff id, full name, gender, district index (null = regional office), job title, department, roles */
    protected const PEOPLE = [
        ['GWA001', 'Esi Boateng-Mensah', 'Female', 1, 'Stores Officer', 'Distribution', ['employee', 'g_water']],
        ['GWA002', 'Kwame Asante-Owusu', 'Male', 4, 'Accounts Officer', 'Finance', ['employee', 'g_water']],
        ['GWA101', 'Yaw Ofori-Addo', 'Male', 1, 'Driver', 'Transport', ['employee', 'driver']],
        ['GWA102', 'Abena Tetteh-Quaye', 'Female', 0, 'Customer Service Officer', 'Commercial', ['employee']],
    ];

    protected const SUPER_ADMIN_STAFF_ID = 'GWSA01';

    /** name, phone: the customers who buy on credit and pay later */
    protected const CUSTOMERS = [
        ['Mama Efua Provisions', '0244120001'],
        ['Kojo Boateng (Kiosk)', '0244120002'],
        ['St. Mary\'s Basic School', '0302120003'],
        ['Nana Yaa Chop Bar', '0244120004'],
        ['Adwoa Mensah', '0244120005'],
        ['Darkuman Market Queens', '0244120006'],
    ];

    protected Randomizer $rng;

    protected Carbon $today;

    protected ?Region $region = null;

    /** @var list<District> */
    protected array $districts = [];

    protected District $office;

    /** @var array<string, Department> */
    protected array $departments = [];

    /** @var array<string, JobTitle> */
    protected array $jobTitles = [];

    /** @var array<string, User> staff id => login */
    protected array $users = [];

    protected User $superAdmin;

    protected GwaterWorkflowService $workflow;

    protected GwaterStockService $stock;

    /** @var array<string, int> */
    protected array $counts = ['trips' => 0, 'sales' => 0, 'credit' => 0, 'receipts' => 0, 'damages' => 0, 'expenses' => 0, 'payments' => 0, 'voided' => 0, 'reimbursed' => 0];

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    protected array $loginRows = [];

    /** @var list<string> */
    protected array $checks = [];

    /** @var array{region_id: int, district_id: int} */
    protected array $place;

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('The G-Water demo seeder never runs in production.');

            return;
        }

        if (! $this->prerequisitesMet()) {
            return;
        }

        $this->rng = new Randomizer(new Mt19937(self::RANDOM_SEED));
        $this->today = today();
        $this->workflow = app(GwaterWorkflowService::class);
        $this->stock = app(GwaterStockService::class);

        $previousMailer = config('mail.default');
        config(['mail.default' => 'array']); // EmployeeObserver sends an invite for each new employee

        try {
            $this->seedLocations();
            $this->seedLookups();
            $this->seedPeople();
            $this->seedAccess();

            if ($this->alreadySeeded()) {
                $this->command?->info('Accra West already has G-Water records (demo trips "'.self::WAYBILL_PREFIX.'..." or an opening balance); nothing was added.');
                $this->printLogins();

                return;
            }

            $this->seedBooks();
        } finally {
            Carbon::setTestNow();
            Auth::guard()->forgetUser();
            config(['mail.default' => $previousMailer]);
        }

        $this->verify();
        $this->printSummary();
    }

    /** What the last run found out; used by the PHPUnit test. @return list<string> */
    public function checks(): array
    {
        return $this->checks;
    }

    // ------------------------------------------------------------------ prerequisites

    protected function prerequisitesMet(): bool
    {
        foreach (['regions', 'districts', 'departments', 'job_titles', 'employees', 'users', 'roles', 'vehicles', 'vehicle_expenses', 'mileage_logs', 'audit_logs', 'gw_products', 'gw_prices', 'gw_purchases', 'gw_sales', 'gw_stock_movements', 'gw_opening_balances', 'gw_opening_balance_lines', 'gw_damages', 'gw_expenses', 'gw_supplier_payments', 'gw_supplier_payment_lines', 'gw_settings'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->command?->error("The {$table} table is missing. Run: php artisan migrate");

                return false;
            }
        }

        foreach (['g_water', 'employee', 'driver', 'super_admin'] as $role) {
            if (! Role::query()->where('name', $role)->exists()) {
                $this->command?->error("The {$role} role is missing. Run: php artisan db:seed");

                return false;
            }
        }

        if (GwProduct::query()->count() < 2) {
            $this->command?->error('The G-Water products are missing. Run: php artisan migrate');

            return false;
        }

        return true;
    }

    protected function alreadySeeded(): bool
    {
        // Demo trips, or any opening balance of this location (the opening balance posts once, so real books are never mixed with demo ones).
        return GwPurchase::query()->where('region_id', $this->region->id)->where('waybill_no', 'like', self::WAYBILL_PREFIX.'%')->exists()
            || GwOpeningBalance::query()->posted()->where('region_id', $this->region->id)->exists();
    }

    // ------------------------------------------------------------------ places and people

    protected function seedLocations(): void
    {
        $this->region = Region::query()->whereRaw('LOWER(TRIM(region_name)) = ?', [Str::lower(self::REGION_NAME)])->orderBy('id')->first()
            ?? Region::query()->create(['region_name' => self::REGION_NAME]);

        $existing = District::query()->where('region_id', $this->region->id)->orderBy('id')->get();

        // Employee::boot() derives location_type from the district NAME: "... regional office" means the regional office.
        $this->office = $existing->first(fn (District $d) => Str::contains(Str::lower($d->district_name), 'regional office'))
            ?? District::query()->create(['region_id' => $this->region->id, 'district_name' => self::OFFICE_NAME]);

        foreach (self::DISTRICTS as $name) {
            // One letter of tolerance, so "Keneshie" reuses an existing "Kaneshie".
            $this->districts[] = $existing->first(fn (District $d) => ! Str::contains(Str::lower($d->district_name), ['regional office', 'head office'])
                && levenshtein(Str::lower($d->district_name), Str::lower($name)) <= 1)
                ?? District::query()->create(['region_id' => $this->region->id, 'district_name' => $name]);
        }
    }

    protected function seedLookups(): void
    {
        foreach (array_unique(array_column(self::PEOPLE, 5)) as $name) {
            $this->departments[$name] = Department::query()->whereRaw('LOWER(TRIM(department_name)) = ?', [Str::lower($name)])->first()
                ?? Department::query()->create(['department_name' => $name]);
        }

        foreach (array_unique(array_column(self::PEOPLE, 4)) as $name) {
            $this->jobTitles[$name] = JobTitle::query()->whereRaw('LOWER(TRIM(job_title_name)) = ?', [Str::lower($name)])->first()
                ?? JobTitle::query()->create(['job_title_name' => $name]);
        }
    }

    protected function seedPeople(): void
    {
        $roleIds = Role::query()->pluck('id', 'name');

        foreach (self::PEOPLE as [$staffId, $name, $gender, $districtIndex, $title, $department, $roles]) {
            $district = $districtIndex === null ? $this->office : $this->districts[$districtIndex];
            $employee = Employee::query()->where('staff_id', $staffId)->first() ?? $this->createEmployee($staffId, $name, $gender, $district, $title, $department);

            if ((int) $employee->region_id !== (int) $this->region->id) {
                throw new RuntimeException("Staff ID {$staffId} already belongs to an employee outside ".self::REGION_NAME.'; refusing to modify it.');
            }

            $user = $this->userFor($employee);
            $user->roles()->syncWithoutDetaching($roleIds->only($roles)->values()->all());
            $this->users[$staffId] = $user->fresh();

            $this->loginRows[] = [$staffId, implode(', ', $roles), $employee->full_name, self::REGION_NAME.' / '.$district->district_name];
        }

        // A super admin is not a member of staff: no employee record, so it picks which location's books to open.
        $this->superAdmin = User::query()->where('staff_id', self::SUPER_ADMIN_STAFF_ID)->first()
            ?? User::query()->create([
                'staff_id' => self::SUPER_ADMIN_STAFF_ID,
                'full_name' => 'Demo G-Water Super Administrator',
                'email' => 'demo.gwater.superadmin@'.self::EMAIL_DOMAIN,
                'password' => Hash::make(self::PASSWORD),
                'is_active' => true,
                'must_change_password' => false,
            ]);
        $this->superAdmin->roles()->syncWithoutDetaching($roleIds->only(['super_admin'])->values()->all());
        $this->loginRows[] = [self::SUPER_ADMIN_STAFF_ID, 'super_admin', $this->superAdmin->full_name, 'every location (pick one at the top of each screen)'];
    }

    /** The g_water role must hold the gwater.* permissions and have module access, whichever order the seeders ran in. */
    protected function seedAccess(): void
    {
        $this->call(GwaterRolePermissionSeeder::class);

        foreach (['g_water', 'super_admin'] as $name) {
            ModuleAccess::query()->updateOrCreate(['role_id' => Role::query()->where('name', $name)->value('id'), 'module' => Permission::MODULE_GWATER], ['can_access' => true]);
        }
    }

    protected function createEmployee(string $staffId, string $name, string $gender, District $district, string $title, string $department): Employee
    {
        $parts = explode(' ', $name);

        // EmployeeObserver::created() creates the login (default password, must change it, an invite to the array mailer).
        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $name,
            'gender' => $gender,
            'category' => 'Senior Staff',
            'email' => Str::lower(Str::ascii($parts[0].'.'.end($parts))).'.'.Str::lower($staffId).'@'.self::EMAIL_DOMAIN,
            'job_title_id' => $this->jobTitles[$title]->id,
            'department_id' => $this->departments[$department]->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'location_type' => Str::contains(Str::lower($district->district_name), 'regional office') ? 'Region' : 'District',
            'date_of_birth' => '1988-04-12',
            'date_joined' => '2016-03-01',
            'present_appointment' => '2019-03-01',
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

    // ------------------------------------------------------------------ the books

    protected function seedBooks(): void
    {
        $recorder = $this->users['GWA001'];
        $approver = $this->users['GWA002'];
        $driver = $this->users['GWA101'];
        $bottle = GwProduct::query()->where('code', 'bottle')->firstOrFail();
        $sachet = GwProduct::query()->where('code', 'sachet')->firstOrFail();
        $this->place = ['region_id' => (int) $this->region->id, 'district_id' => (int) $this->districts[1]->id];

        $yesterday = $this->today->copy()->subDay();
        $start = $yesterday->copy()->subDays(self::DAYS);
        $vehicle = $this->vehicle($driver);

        // The location's own price list, before any entry: the placeholder list is 25.00 / 10.00 (bottle 26.00 from 12 May 2026);
        // this location pays a little more and rises again part-way through the history.
        $this->actingAt($start, $recorder);
        $this->workflow->addPrice($recorder, ['product_id' => $bottle->id, 'effective_from' => $start->toDateString(), 'selling_price' => 2700, 'cost_price' => 2200, 'note' => 'Accra West price list']);
        $this->workflow->addPrice($recorder, ['product_id' => $sachet->id, 'effective_from' => $start->toDateString(), 'selling_price' => 1100, 'cost_price' => 850, 'note' => 'Accra West price list']);
        $rise = $start->copy()->addDays(40);
        $this->actingAt($rise, $recorder);
        $this->workflow->addPrice($recorder, ['product_id' => $bottle->id, 'effective_from' => $rise->toDateString(), 'selling_price' => 2900, 'cost_price' => 2400, 'note' => 'Price increase']);
        $this->workflow->addPrice($recorder, ['product_id' => $sachet->id, 'effective_from' => $rise->toDateString(), 'selling_price' => 1200, 'cost_price' => 950, 'note' => 'Price increase']);

        $this->actingAt($start, $recorder);
        $this->workflow->recordOpeningBalance($recorder, [
            'as_of_date' => $start->toDateString(),
            'packs' => [$bottle->id => 400, $sachet->id => 600],
            'outstanding' => [$bottle->id => 150, $sachet->id => 200],
            'owed_amount' => 320000,
            'note' => 'Brought forward from the paper ledger',
        ]);

        /** @var list<array{day: Carbon, packs: array<int, int>, trip: GwPurchase}> $delivered trips, for the payment requests */
        $delivered = [];
        $toPay = [];       // [paying day, kind, id, reference, amount for a customer's payment]
        $tripNumber = 0;
        $payments = 0;

        for ($day = $start->copy()->addDay(); $day->lte($yesterday); $day->addDay()) {
            $age = (int) $start->diffInDays($day);

            // Money paid back, and payment requests marked paid, on their later day.
            foreach ($toPay as $index => [$when, $kind, $id, $reference, $amount]) {
                if ($day->toDateString() === $when) {
                    if ($kind === 'payment') {
                        $this->actingAt($day, $recorder);
                        $this->workflow->markPaymentPaid(GwSupplierPayment::query()->findOrFail($id), $recorder, $day->toDateString());
                    } elseif ($kind === 'receipt') {
                        // A customer comes to pay what they owe, all or part of it.
                        $this->actingAt($day, $recorder);
                        $this->workflow->recordSaleReceipt($recorder, $id, ['amount' => $amount, 'received_on' => $day->toDateString(), 'reference' => $reference]);
                        $this->counts['receipts']++;
                    } else {
                        // Someone other than the person who recorded the trip, as the rule demands.
                        $this->actingAt($day, $approver);
                        $this->workflow->markReimbursed($approver, $kind, $id, $reference, $day->toDateString());
                        $this->counts['reimbursed']++;
                    }

                    unset($toPay[$index]);
                }
            }

            // A collection trip every week.
            if ($age % 7 === 3) {
                $this->actingAt($day, $recorder);
                $trip = $this->trip($day, ++$tripNumber, $bottle, $sachet, $recorder, $driver, $vehicle);
                $delivered[] = ['day' => $day->copy(), 'trip' => $trip];

                // Fuel paid out of pocket on older trips is paid back a week later; the latest ones stay pending.
                if ($trip['reimburse'] && $age <= self::DAYS - 25) {
                    $toPay[] = [$day->copy()->addDays(7)->toDateString(), $trip['kind'], $trip['id'], 'CHQ-'.(5000 + $tripNumber), null];
                }
            }

            // Sales Monday to Saturday, never more than is on the shelf.
            if (! $day->isSunday()) {
                $this->actingAt($day, $recorder);

                foreach ([[$bottle, 25, 55], [$sachet, 20, 45]] as [$product, $min, $max]) {
                    $packs = min($this->rng->getInt($min, $max), $this->stock->balance($this->place, $product->id));

                    if ($packs >= 5) {
                        // About one sale in seven goes to a customer who pays later (never the one that is voided below).
                        $onCredit = $packs >= 10 && ! ($age === 20 && $product->is($bottle)) && $this->rng->getInt(1, 100) <= 14;
                        $customer = self::CUSTOMERS[$this->rng->getInt(0, count(self::CUSTOMERS) - 1)];

                        $sale = $this->workflow->recordSale($recorder, ['sale_date' => $day->toDateString(), 'product_id' => $product->id, 'packs' => $packs]
                            + ($onCredit ? ['payment_type' => 'credit', 'customer_name' => $customer[0], 'customer_phone' => $customer[1]] : []));
                        $this->counts['sales']++;

                        if ($onCredit) {
                            $this->counts['credit']++;
                            $this->planCustomerPayment($toPay, $sale, $day, $this->rng->getInt(1, 100), $this->rng->getInt(4, 18));
                        }

                        // One entry typed on the wrong day and voided, as it happens.
                        if ($age === 20 && $product->is($bottle)) {
                            $this->workflow->voidSale($sale, $recorder, 'Entered twice by mistake');
                            $this->counts['voided']++;
                        }
                    }
                }
            }

            // Three credit stories that are always there: an old debt nobody has paid, a part payment, and one paid in full.
            foreach ([[30, $bottle, 12, 'unpaid', 0], [45, $sachet, 20, 'part', 10], [50, $bottle, 10, 'full', 6]] as $index => [$onAge, $product, $count, $outcome, $after]) {
                if ($age === $onAge && $this->stock->balance($this->place, $product->id) >= $count) {
                    $this->actingAt($day, $recorder);
                    $story = self::CUSTOMERS[$index];
                    $sale = $this->workflow->recordSale($recorder, ['sale_date' => $day->toDateString(), 'product_id' => $product->id, 'packs' => $count, 'payment_type' => 'credit', 'customer_name' => $story[0], 'customer_phone' => $story[1], 'note' => 'On credit']);
                    $this->counts['sales']++;
                    $this->counts['credit']++;
                    $this->planCustomerPayment($toPay, $sale, $day, $outcome === 'full' ? 1 : ($outcome === 'part' ? 70 : 100), $after);
                }
            }

            // A few damages, only when there is stock to lose.
            if ($age % 17 === 9 && $this->stock->balance($this->place, $bottle->id) >= 20) {
                $this->actingAt($day, $recorder);
                $this->workflow->recordDamage($recorder, [
                    'damage_date' => $day->toDateString(),
                    'product_id' => $bottle->id,
                    'packs' => $this->rng->getInt(3, 8),
                    'stage' => ['transit', 'offloading', 'storage'][$this->rng->getInt(0, 2)],
                    'reason' => ['Burst packs in the truck', 'Dropped while offloading', 'Crushed under a stack in the store'][$this->rng->getInt(0, 2)],
                ]);
                $this->counts['damages']++;
            }

            // A cost that belongs to no trip.
            if ($age % 13 === 6) {
                $this->actingAt($day, $recorder);
                $this->workflow->recordExpense($recorder, [
                    'expense_date' => $day->toDateString(),
                    'category' => 'other',
                    'amount' => $this->rng->getInt(2, 9) * 2500,
                    'payee' => ['Hardware shop', 'Cleaning supplies', 'Trolley repair'][$this->rng->getInt(0, 2)],
                    'receipt_ref' => 'RCPT-'.(300 + $age),
                ]);
                $this->counts['expenses']++;
            }

            // A payment request to G Water every fortnight for what was delivered since the last one. Most are paid three days
            // later; the one from five weeks ago is left unpaid (overdue) and the latest one is still waiting.
            if ($age >= 14 && $age % 14 === 0 && $delivered !== []) {
                $this->actingAt($day, $recorder);
                $payments++;
                $lines = $this->deliveredLines($delivered, $day->copy()->subDays(14), $day, $bottle, $sachet);

                if ($lines !== []) {
                    $payment = $this->workflow->recordPayment($recorder, ['request_date' => $day->toDateString(), 'reference' => 'PR-'.(2600 + $payments), 'lines' => $lines]);
                    $this->counts['payments']++;

                    $overdueOne = $age === 28;
                    $latest = $age + 14 > self::DAYS;

                    if (! $overdueOne && ! $latest) {
                        $toPay[] = [$day->copy()->addDays(3)->toDateString(), 'payment', $payment->id, '', null];
                    }
                }
            }
        }

        // Those waiting for a day that never came stay pending: that is the demo (fuel to pay back).
        $this->actingAt($yesterday, $recorder);

        // Low-stock limits of this location, so the Overview shows what a flag looks like.
        // Sachet is set just above what is left, so it is flagged; bottle keeps a limit well below its stock.
        $sachetLimit = (int) (ceil(($this->stock->balance($this->place, $sachet->id) + 30) / 10) * 10);
        $this->workflow->saveSettings($recorder, ['low_stock_sachet' => $sachetLimit, 'low_stock_bottle' => 100]);
    }

    /**
     * Decides what a credit customer does: pays it all ($roll up to 55), pays about half ($roll up to 80) or nothing yet, a number of
     * days after the sale. Payments that would fall after yesterday simply never come (the debt is still open).
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: int|null}>  $toPay
     */
    protected function planCustomerPayment(array &$toPay, GwSale $sale, Carbon $day, int $roll, int $afterDays): void
    {
        if ($roll > 80 || $afterDays < 1) {
            return;
        }

        $amount = $roll <= 55 ? $sale->amount : max(100, (int) (round($sale->amount / 2 / 100) * 100));
        $toPay[] = [$day->copy()->addDays($afterDays)->toDateString(), 'receipt', $sale->id, 'RC-'.(8000 + $sale->id), $amount];
    }

    /**
     * One weekly trip. Fuel comes from Transport (vehicle, mileage entry and fuel expense linked), or is typed in G-Water, or
     * is paid out of pocket by the driver; loading and offloading are paid in cash.
     *
     * @return array{id: int, kind: string, reimburse: bool, model: GwPurchase, packs: array<int, int>}
     */
    protected function trip(Carbon $day, int $number, GwProduct $bottle, GwProduct $sachet, User $recorder, User $driver, Vehicle $vehicle): array
    {
        $packs = [$bottle->id => $this->rng->getInt(18, 32) * 10, $sachet->id => $this->rng->getInt(12, 24) * 10];
        $data = [
            'purchase_date' => $day->toDateString(),
            'waybill_no' => self::WAYBILL_PREFIX.(2900 + $number),
            'supplier_ref' => 'DN-'.(7100 + $number * 3),
            'lines' => [['product_id' => $bottle->id, 'packs' => $packs[$bottle->id]], ['product_id' => $sachet->id, 'packs' => $packs[$sachet->id]]],
            'loading_amount' => $this->rng->getInt(5, 9) * 500,
            'offloading_amount' => $this->rng->getInt(3, 6) * 500,
            'driver_id' => $driver->id,
        ];
        $fuel = $this->rng->getInt(24, 40) * 500;
        $mode = $number % 3;
        $reimburse = false;
        $kind = 'expense';

        if ($mode === 0) {
            // Transport: the vehicle, the run and the fuel receipt are Transport's own rows, only read here.
            $before = (int) $vehicle->fresh()->current_mileage;
            $log = MileageLog::query()->create(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'mileage_before' => $before, 'mileage_after' => $before + $this->rng->getInt(60, 120), 'trip_date' => $day->toDateString(), 'trip_purpose' => 'Collect water from G Water']);
            $expense = VehicleExpense::query()->create(['vehicle_id' => $vehicle->id, 'expense_type' => 'fuel', 'amount' => $fuel / 100, 'currency' => 'GHS', 'description' => 'Fuel for the water run', 'expense_date' => $day->toDateString(), 'recorded_by' => $recorder->id]);
            $data += ['vehicle_id' => $vehicle->id, 'mileage_log_id' => $log->id, 'vehicle_expense_id' => $expense->id];

            // Every other Transport trip: the driver paid the fuel himself.
            if (intdiv($number, 3) % 2 === 0) {
                $data['fuel_paid_by_user_id'] = $driver->id;
                $reimburse = true;
                $kind = 'trip';
            }
        } elseif ($mode === 1) {
            // Fuel typed in G-Water, paid by the driver out of pocket: to be paid back.
            $data += ['fuel_amount' => $fuel, 'fuel_paid_by_user_id' => $driver->id, 'receipt_ref' => 'FUEL-'.(400 + $number)];
            $reimburse = true;
        } else {
            // The company paid for the fuel: nothing to pay back.
            $data += ['fuel_amount' => $fuel, 'receipt_ref' => 'FUEL-'.(400 + $number)];
        }

        $trip = $this->workflow->recordPurchase($recorder, $data);
        $this->counts['trips']++;

        $id = $kind === 'trip' ? $trip->id : (int) GwExpense::query()->where('purchase_id', $trip->id)->where('category', 'fuel')->value('id');

        return ['id' => $id, 'kind' => $kind, 'reimburse' => $reimburse && $id > 0, 'model' => $trip, 'packs' => $packs];
    }

    /**
     * The packs delivered between two days, as payment-request lines (price per pack: the cost price in force).
     *
     * @param  list<array{day: Carbon, trip: array{packs: array<int, int>}}>  $delivered
     * @return list<array{product_id: int, packs: int}>
     */
    protected function deliveredLines(array $delivered, Carbon $from, Carbon $to, GwProduct $bottle, GwProduct $sachet): array
    {
        $totals = [$bottle->id => 0, $sachet->id => 0];

        foreach ($delivered as $item) {
            if ($item['day']->gt($from) && $item['day']->lte($to)) {
                foreach ($item['trip']['packs'] as $productId => $count) {
                    $totals[$productId] += $count;
                }
            }
        }

        return collect($totals)->filter()->map(fn (int $count, int $productId) => ['product_id' => $productId, 'packs' => $count])->values()->all();
    }

    /** The Accra West pickup the trips use: a vehicle in the region (visible to its users through Transport's own scope). */
    protected function vehicle(User $driver): Vehicle
    {
        return Vehicle::query()->where('number_plate', self::VEHICLE_PLATE)->first() ?? Vehicle::query()->create([
            'type' => Vehicle::TYPE_PICKUP,
            'brand' => 'Toyota',
            'model' => 'Hilux',
            'color' => 'White',
            'number_plate' => self::VEHICLE_PLATE,
            'year_purchased' => 2024,
            'is_pool_car' => true,
            'driver_type' => Vehicle::DRIVER_ASSIGNED,
            'assigned_driver_id' => $driver->id,
            'current_mileage' => 18000,
            'maintenance_interval_km' => 5000,
            'insurance_expiry_date' => $this->today->copy()->addMonths(5)->toDateString(),
            'road_worthiness_expiry_date' => $this->today->copy()->addMonths(4)->toDateString(),
            'status' => Vehicle::STATUS_ACTIVE,
            'region_id' => $this->region->id,
            'district_id' => $this->districts[1]->id,
        ]);
    }

    /** Moves the clock to a day and makes a user the one acting (so authorship and the audit rows are theirs). */
    protected function actingAt(Carbon $day, User $user): void
    {
        Carbon::setTestNow($day->copy()->setTime(9, 0));
        Auth::guard()->setUser($user);
    }

    // ------------------------------------------------------------------ checks and the summary

    protected function verify(): void
    {
        $summary = app(GwaterSummaryService::class);
        $profit = app(GwaterProfitService::class);
        $recorder = $this->users['GWA001'];

        foreach (GwProduct::query()->active()->get() as $product) {
            $onHand = $this->stock->balance($this->place, $product->id);
            $this->checks[] = $product->code.' stock on hand: '.$onHand;

            if ($onHand < 0) {
                throw new RuntimeException("Stock of {$product->code} is negative.");
            }
        }

        $this->checks[] = 'owed to G Water: GH¢ '.Money::format($summary->owedToGwater($this->place));
        $owe = $summary->owedByCustomers($this->place);
        $this->checks[] = 'owed by customers: GH¢ '.Money::format($owe['total']).' ('.$owe['customers'].' customer(s) on '.$owe['count'].' credit sale(s))';
        $this->checks[] = 'fuel waiting to be paid back: '.$summary->pendingReimbursements($this->place)->count().' item(s)';
        $this->checks[] = 'payment requests: '.GwSupplierPayment::query()->where('region_id', $this->region->id)->where('status', 'requested')->count().' waiting';

        $month = $profit->report($this->place, $this->today->copy()->subMonthNoOverflow()->startOfMonth(), $this->today->copy()->subMonthNoOverflow()->endOfMonth(), $recorder);
        $this->checks[] = 'last month: revenue GH¢ '.Money::format($month['combined']['revenue']).', net profit GH¢ '.Money::format($month['combined']['net_profit']);
    }

    protected function printLogins(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->newLine();
        $this->command->table(['Staff ID', 'Roles', 'Name', 'Location'], $this->loginRows);
        $this->command->line('Password for every login above: '.self::PASSWORD.'. Turn the module on with GWL_GWATER_MODULE_ENABLED=true in .env.');
    }

    protected function printSummary(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info(sprintf(
            'G-Water demo data for %s: %d trips, %d sales (%d on credit, %d customer payments, %d voided), %d damages, %d other expenses, %d payment requests, %d fuel items paid back.',
            self::REGION_NAME,
            $this->counts['trips'],
            $this->counts['sales'],
            $this->counts['credit'],
            $this->counts['receipts'],
            $this->counts['voided'],
            $this->counts['damages'],
            $this->counts['expenses'],
            $this->counts['payments'],
            $this->counts['reimbursed']
        ));

        foreach ($this->checks as $check) {
            $this->command->line('  '.$check);
        }

        $this->printLogins();
    }
}
