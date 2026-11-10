<?php

namespace App\Services\Finance;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvBatch;
use App\Models\FinPvCategory;
use App\Models\FinPvType;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\GWater\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Realistic DEMO data for the Finance and Audit modules (docs/20-module-finance-audit.md), for trying the screens, reports and
 * reminders without real vouchers: `php artisan finance:demo-data [--with-users] [--remove]` and Database\Seeders\FinanceAuditDemoSeeder
 * (not called from DatabaseSeeder). It REFUSES to run in production, and unless the module flag is on.
 *
 * Everything is created THROUGH the real services (PaymentVoucherWorkflowService, SupplierService, PvCatalogService), acting as demo
 * accounts that hold the real roles, with the clock set back (Carbon::setTestNow) so events, rounds, audit rows, turnaround and ageing
 * figures are genuine; notices (and the invitation e-mail a new staff record would send) are suppressed for the run.
 *
 * What it makes, for Head Office and up to three regions (a location = Head Office, or a region without it):
 *  - four demo accounts per location (finance officer, finance manager, audit officer, audit manager): demo STAFF records with staff ids
 *    DEMO-<LOC>-..., so they are valid accounts that follow how Employee -> User sync works. Without --with-users they are INACTIVE
 *    (nobody can sign in as them); --with-users (local / testing only) activates them with a random password, printed once, and
 *    must_change_password set;
 *  - about 25 suppliers company-wide, every name ending " (Demo)", some with a TIN and phone, some with a name only;
 *  - 60 vouchers per location numbered DEMO-<LOC>-0001..., spread over the last six months through every status and path (Draft; With
 *    Audit aged 0-2, 3-7, 8-14 and 15+ days; Queried once and twice; resubmitted; Audit acknowledged waiting for Finance; Completed;
 *    tick withdrawn then re-reviewed; Voided), paying employees, retirees, districts and suppliers, two of them to staff who have since
 *    left service; the first region also gets a location-owned type, a renamed type and a switched-off type.
 * Existing employees and districts are used for payees; Staff data is never altered (only demo staff records are created or changed).
 * Running it again does nothing while DEMO- vouchers exist. remove() deletes ONLY the demo rows (DEMO- vouchers with their events,
 * attachments and reminder states, suppliers ending " (Demo)" that no other voucher uses, demo catalogue rows, demo accounts with the
 * audit rows they wrote), with direct deletes where the immutable model hook would refuse, and nothing else.
 */
class FinanceDemoDataService
{
    public const PREFIX = 'DEMO-';

    public const SUPPLIER_SUFFIX = ' (Demo)';

    /** The most regions (besides Head Office) the demo data is made for. */
    public const MAX_REGIONS = 3;

    public const PER_LOCATION = 60;

    /** @var list<string> */
    protected const SUPPLIERS = [
        'Accra Stationery Mart', 'Tema Pipes and Fittings', 'Osu Office Supplies', 'Kumasi Hardware Centre', 'Adabraka Printing Works',
        'Labone Fuel Services', 'Spintex Electrical Wholesale', 'Takoradi Marine Chemicals', 'Cape Coast Building Materials', 'Madina Computer Hub',
        'Kaneshie Safety Gear', 'Tamale Plumbing Traders', 'Koforidua Water Parts', 'Ho Valve and Meter Company', 'Sunyani Tyre Depot',
        'Dansoman Catering Services', 'Legon Cleaning Supplies', 'Achimota Motor Spares', 'Airport City Couriers', 'Nungua Uniform Makers',
        'Weija Chemical Traders', 'Kasoa Cement Dealers', 'Obuasi Equipment Hire', 'Bolgatanga Pump Services', 'Winneba Freight Agency',
    ];

    protected Carbon $now;

    /** Batches made in this run. */
    protected int $batchCount = 0;

    /**
     * Which plan entries go into a batch instead of being made one by one: scenario => [[how many of that scenario to skip, how many to take, batch kind]].
     * The vouchers keep their statuses (so the status mix of the demo data does not change); the oldest With Audit ones stay single so every
     * ageing bucket is still filled, and some single vouchers stay unassigned.
     */
    protected const BATCH_SLOTS = [
        'draft' => [[0, 3, 'draft']],
        'with_audit' => [[4, 3, 'awaiting'], [7, 2, 'progress'], [9, 2, 'split']],
        'ticked' => [[0, 1, 'progress'], [1, 2, 'split']],
        'completed' => [[0, 3, 'complete']],
    ];

    /** @var list<array{account: string, staff_id: string, password: string}> */
    protected array $credentials = [];

    public function __construct(
        protected PaymentVoucherWorkflowService $workflow,
        protected PvCatalogService $catalogue,
        protected SupplierService $suppliers,
        protected FinanceScope $scope,
        protected BatchWorkflowService $batches,
    ) {}

    // ------------------------------------------------------------------ guards

    /** @throws \RuntimeException when this must not run here */
    public function assertAllowed(bool $withUsers = false): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Refusing to run in production: demo data never belongs there.');
        }

        if (! config('gwl.finance_audit_enabled')) {
            throw new \RuntimeException('The Finance and Audit module is switched off (GWL_FINANCE_AUDIT_ENABLED), so there is nowhere to put demo data.');
        }

        if ($withUsers && ! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('--with-users creates accounts people can sign in with, so it only runs in the local or testing environment.');
        }
    }

    /** Whether any demo voucher exists. */
    public function exists(): bool
    {
        return Pv::query()->where('pv_no_key', 'like', self::PREFIX.'%')->exists();
    }

    // ------------------------------------------------------------------ seeding

    /**
     * @param  callable(string): void|null  $say  progress lines
     * @return array{skipped: bool, locations: int, vouchers: int, suppliers: int, accounts: int, credentials: list<array{account: string, staff_id: string, password: string}>}
     */
    public function seed(bool $withUsers = false, ?callable $say = null): array
    {
        $this->assertAllowed($withUsers);
        $say ??= fn (string $line) => null;
        $this->credentials = [];
        $this->batchCount = 0;

        if ($this->exists()) {
            $say('Demo vouchers already exist, so nothing was added. Remove them first with --remove.');

            return ['skipped' => true, 'locations' => 0, 'vouchers' => 0, 'batches' => 0, 'suppliers' => 0, 'accounts' => 0, 'credentials' => []];
        }

        $locations = $this->locations();

        if ($locations === []) {
            throw new \RuntimeException('No Head Office district or region with a district was found, so no location can hold demo data.');
        }

        $originalNotifier = Notification::getFacadeRoot();
        $originalNow = Carbon::getTestNow();
        $originalUser = Auth::user();
        $this->now = Carbon::now()->startOfMinute();
        Notification::fake();
        mt_srand(20261107);

        try {
            $accounts = 0;

            foreach ($locations as $index => $location) {
                $locations[$index]['people'] = $this->makeAccounts($location, $withUsers);
                $accounts += count($locations[$index]['people']);
                $say("{$location['label']}: demo accounts ready.");
            }

            $suppliers = $this->makeSuppliers($locations[0]['people']['fo']);
            $say(count($suppliers).' demo suppliers made.');

            $vouchers = 0;

            foreach ($locations as $index => $location) {
                $vouchers += $this->makeLocation($location, $index === 1 || (count($locations) === 1), $suppliers);
                $say("{$location['label']}: ".self::PER_LOCATION.' demo vouchers made.');
            }
        } finally {
            // Even when the run failed half way: nobody can sign in as a demo account unless --with-users asked for it.
            if (! $withUsers) {
                User::query()->where('staff_id', 'like', self::PREFIX.'%')->update(['is_active' => false]);
            }

            Carbon::setTestNow($originalNow);
            Notification::swap($originalNotifier);
            $originalUser ? Auth::setUser($originalUser) : Auth::forgetUser();
            FinanceBooksVersion::touchAll();
        }

        return ['skipped' => false, 'locations' => count($locations), 'vouchers' => $vouchers, 'batches' => $this->batchCount, 'suppliers' => count($suppliers), 'accounts' => $accounts, 'credentials' => $this->credentials];
    }

    /**
     * Head Office (if there is one) and up to MAX_REGIONS regions that have a district other than Head Office.
     *
     * @return list<array{key: string, label: string, district: District, people?: array<string, User>}>
     */
    protected function locations(): array
    {
        $headOffice = District::query()->whereRaw('LOWER(district_name) LIKE ?', ['%head office%'])->orderBy('id')->first();
        $found = [];

        if ($headOffice) {
            $found[] = ['key' => 'HO', 'label' => 'Head Office', 'district' => $headOffice];
        }

        $regions = [];

        foreach (Region::query()->orderBy('id')->get() as $region) {
            $district = District::query()->where('region_id', $region->id)->whereRaw('LOWER(district_name) NOT LIKE ?', ['%head office%'])->orderBy('id')->first();

            if ($district && count($regions) < self::MAX_REGIONS) {
                $regions[] = ['region' => $region, 'district' => $district];
            }
        }

        // A short readable code per region: its letter prefix, made unique with the id if two regions share it.
        $prefixes = collect($regions)->map(fn (array $row) => Str::upper((string) ($row['region']->letter_prefix ?: Region::deriveLetterPrefix($row['region']->region_name))));

        foreach ($regions as $i => $row) {
            $code = $prefixes[$i];
            $code = $prefixes->filter(fn ($other) => $other === $code)->count() > 1 || $code === 'HO' ? $code.$row['region']->id : $code;
            $found[] = ['key' => $code, 'label' => $row['region']->region_name, 'district' => $row['district']];
        }

        return $found;
    }

    // ------------------------------------------------------------------ accounts and people

    /** @return array<string, User> fo, fm, ao, am */
    protected function makeAccounts(array $location, bool $withUsers): array
    {
        $roles = ['fo' => ['finance_officer', 'Finance Officer'], 'fm' => ['finance_manager', 'Finance Manager'], 'ao' => ['audit_officer', 'Audit Officer'], 'ao2' => ['audit_officer', 'Audit Officer 2'], 'am' => ['audit_manager', 'Audit Manager']];
        $people = [];

        foreach ($roles as $short => [$role, $title]) {
            $staffId = self::PREFIX.$location['key'].'-'.Str::upper($short);
            $employee = $this->employee($staffId, "Demo {$title} ({$location['label']})", $location['district']);
            $user = User::query()->where('staff_id', $employee->staff_id)->firstOrFail();
            $password = Str::random(14);

            // Active while the history is made (the manager can only assign to an ACTIVE officer); seed() switches them off at the end
            // unless the accounts are meant to be used.
            $user->forceFill(['password' => $password, 'must_change_password' => true, 'is_active' => true])->save();
            $user->roles()->syncWithoutDetaching(Role::query()->whereIn('name', ['employee', $role])->pluck('id')->all());

            if ($withUsers) {
                $this->credentials[] = ['account' => "{$title}, {$location['label']}", 'staff_id' => $staffId, 'password' => $password];
            }

            $people[$short] = $user->fresh();
        }

        return $people;
    }

    /** A demo staff record (the observer creates its user the way every staff record gets one). */
    protected function employee(string $staffId, string $name, District $district, array $extra = []): Employee
    {
        $existing = Employee::query()->where('staff_id', $staffId)->first();

        if ($existing) {
            return $existing;
        }

        return Employee::query()->create(array_merge([
            'staff_id' => $staffId,
            'full_name' => $name,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => Str::lower($staffId).'@demo.invalid',
            'job_title_id' => (JobTitle::query()->orderBy('id')->first() ?? JobTitle::query()->create(['job_title_name' => 'General Staff']))->id,
            'department_id' => (Department::query()->orderBy('id')->first() ?? Department::query()->create(['department_name' => 'General']))->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'date_of_birth' => '1985-06-15',
            'date_joined' => '2015-01-12',
            'present_appointment' => '2019-03-01',
            'is_active' => true,
        ], $extra));
    }

    // ------------------------------------------------------------------ suppliers

    /** @return list<int> the ids of the demo suppliers, in order */
    protected function makeSuppliers(User $actor): array
    {
        $this->as($actor, $this->now->copy()->subDays(175));
        $ids = [];

        foreach (self::SUPPLIERS as $i => $name) {
            $data = ['name' => $name.self::SUPPLIER_SUFFIX];

            if ($i % 2 === 0) {
                $data['tin'] = sprintf('P%010d', 9990000001 + $i);
                $data['phone'] = sprintf('024%07d', 5550001 + $i);
            }

            if ($i % 5 === 0) {
                $data['code'] = sprintf('DEMO-S%02d', $i + 1);
            }

            $ids[] = $this->suppliers->create($actor, $data, true)->id;
        }

        return $ids;
    }

    // ------------------------------------------------------------------ one location

    /** @param list<int> $suppliers */
    protected function makeLocation(array $location, bool $changesCatalogue, array $suppliers): int
    {
        $people = $location['people'];
        $district = $location['district'];
        $place = ['region_id' => $district->region_id, 'district_id' => $district->id];

        // People to pay: existing staff and retirees of the location first, topped up with demo ones; two demo staff who leave later.
        $employees = $this->scope->employees($people['fo'], Pv::PAYEE_EMPLOYEE, $place)->where('employees.staff_id', 'not like', self::PREFIX.'%')->limit(3)->pluck('employees.id')->all();

        for ($n = 1; count($employees) < 3; $n++) {
            $employees[] = $this->employee(self::PREFIX.$location['key'].'-S'.$n, "Demo Staff {$n} ({$location['label']})", $district)->id;
        }

        $retirees = $this->scope->employees($people['fo'], Pv::PAYEE_RETIREE, $place)->where('employees.staff_id', 'not like', self::PREFIX.'%')->limit(2)->pluck('employees.id')->all();

        for ($n = 1; count($retirees) < 2; $n++) {
            $retirees[] = $this->employee(self::PREFIX.$location['key'].'-R'.$n, "Demo Retiree {$n} ({$location['label']})", $district, ['is_active' => false, 'deactivation_reason' => 'retired'])->id;
        }

        $leavers = [
            $this->employee(self::PREFIX.$location['key'].'-L1', "Demo Leaver 1 ({$location['label']})", $district),
            $this->employee(self::PREFIX.$location['key'].'-L2', "Demo Leaver 2 ({$location['label']})", $district),
        ];
        $districts = $this->scope->districts($people['fo'], $place)->pluck('districts.id')->all();

        // A location-owned type (the first region only) so the voucher form and reports show one.
        if ($changesCatalogue) {
            $this->as($people['fm'], $this->now->copy()->subDays(178));
            $category = $this->catalogue->effective($place)->first(fn ($c) => $c->is_default);
            $this->catalogue->createType($people['fm'], $category->id, 'Fuel and Lubricants'.self::SUPPLIER_SUFFIX);
        }

        $types = $this->catalogue->effective($place)->flatMap(fn (FinPvCategory $c) => $c->types->filter(fn (FinPvType $t) => $t->effective_active)->map(fn (FinPvType $t) => ['category' => $c->id, 'type' => $t->id]))->values()->all();

        $plan = collect($this->plan())->sortByDesc(fn (array $step) => $step[1])->values();
        $pools = ['employees' => $employees, 'retirees' => $retirees, 'districts' => $districts, 'suppliers' => $suppliers, 'leavers' => $leavers];
        $leaverVouchers = 0;

        $seen = [];
        $groups = [];

        foreach ($plan as $i => [$scenario, $age]) {
            $payee = $this->payee($i, $pools, $scenario, $leaverVouchers);
            $type = $types[$i % count($types)];
            $number = sprintf('%s%s-%04d', self::PREFIX, $location['key'], $i + 1);
            $kind = $this->batchKind($scenario, $seen[$scenario] = ($seen[$scenario] ?? -1) + 1);

            if ($kind !== null) {
                $groups[$kind][] = compact('scenario', 'number', 'type', 'payee', 'i');

                continue;
            }

            $this->scenario($scenario, $age, $people, $number, $type, $payee, $i);
        }

        // The batches: a draft one, one sent and awaiting assignment, one in progress, one split across two officers, one completed.
        foreach (['draft', 'awaiting', 'progress', 'split', 'complete'] as $kind) {
            if (! empty($groups[$kind])) {
                $this->makeBatch($people, $kind, $groups[$kind]);
            }
        }

        // The two leavers resign AFTER the vouchers paid to them (so those vouchers are flagged "left service since").
        Carbon::setTestNow($this->now);

        foreach ($leavers as $leaver) {
            $leaver->update(['is_active' => false, 'deactivation_reason' => 'resigned']);
        }

        if ($changesCatalogue) {
            // A rename and a switch-off for this location only, after the vouchers (which keep what they used).
            $this->as($people['fm'], $this->now->copy()->subDays(1));
            $defaults = $this->catalogue->effective($place)->flatMap(fn (FinPvCategory $c) => $c->types)->filter(fn (FinPvType $t) => $t->is_default)->values();
            $renamed = $defaults->get(1) ?? $defaults->first();
            $off = $defaults->last();
            $this->catalogue->renameType($people['fm'], $renamed->id, $renamed->effective_name.self::SUPPLIER_SUFFIX);

            if ($off && $off->id !== $renamed->id) {
                $this->catalogue->setTypeActive($people['fm'], $off->id, false);
            }
        }

        return $plan->count();
    }

    /** The vouchers of one location: [scenario, age in days of its latest step]. @return list<array{0: string, 1: int}> */
    protected function plan(): array
    {
        $plan = [];
        $add = function (string $scenario, array $ages) use (&$plan): void {
            foreach ($ages as $age) {
                $plan[] = [$scenario, $age];
            }
        };

        $add('draft', [1, 3, 9, 12, 20, 35]);
        $add('with_audit', [0, 1, 2, 1, 3, 5, 6, 7, 9, 11, 14, 16, 24, 45]);   // 0-2, 3-7, 8-14 and 15+ days
        $add('queried', [1, 2, 4, 6, 9]);
        $add('queried_twice', [2, 4]);
        $add('resubmitted', [1, 3, 6]);
        $add('ticked', [1, 3, 5, 8, 10]);
        $add('completed_query', [20, 48, 95, 140]);
        $add('completed', [8, 12, 15, 22, 30, 41, 55, 63, 78, 90, 104, 118, 130, 165]);
        $add('withdrawn_ticked', [12, 30]);
        $add('withdrawn_completed', [60]);
        $add('void_draft', [20]);
        $add('void_with_audit', [12]);
        $add('void_queried', [25, 45]);

        return $plan;
    }

    /** Who voucher number $i is paid to, cycling through all four kinds; two completed ones go to staff who later leave. */
    protected function payee(int $i, array $pools, string $scenario, int &$leaverVouchers): array
    {
        if ($scenario === 'completed' && $leaverVouchers < 2) {
            return ['payee_kind' => Pv::PAYEE_EMPLOYEE, 'payee_employee_id' => $pools['leavers'][$leaverVouchers++]->id];
        }

        $kinds = [Pv::PAYEE_EMPLOYEE, Pv::PAYEE_SUPPLIER, Pv::PAYEE_DISTRICT, Pv::PAYEE_SUPPLIER, Pv::PAYEE_EMPLOYEE, Pv::PAYEE_RETIREE, Pv::PAYEE_SUPPLIER, Pv::PAYEE_DISTRICT, Pv::PAYEE_EMPLOYEE, Pv::PAYEE_SUPPLIER];

        return match ($kinds[$i % count($kinds)]) {
            Pv::PAYEE_EMPLOYEE => ['payee_kind' => Pv::PAYEE_EMPLOYEE, 'payee_employee_id' => $pools['employees'][$i % count($pools['employees'])]],
            Pv::PAYEE_RETIREE => ['payee_kind' => Pv::PAYEE_RETIREE, 'payee_employee_id' => $pools['retirees'][$i % count($pools['retirees'])]],
            Pv::PAYEE_DISTRICT => ['payee_kind' => Pv::PAYEE_DISTRICT, 'payee_district_id' => $pools['districts'][$i % count($pools['districts'])]],
            default => ['payee_kind' => Pv::PAYEE_SUPPLIER, 'supplier_id' => $pools['suppliers'][$i % count($pools['suppliers'])]],
        };
    }

    // ------------------------------------------------------------------ the paths a voucher can take

    /**
     * Plays one voucher through the real workflow with the clock set back. $age is the age in days of its LATEST step, so a voucher
     * With Audit for 5 days is 5 days old at the moment it was sent. The submitter is the finance officer, the reviewer the audit officer.
     */
    protected function scenario(string $scenario, int $age, array $people, string $number, array $type, array $payee, int $i): void
    {
        $fo = $people['fo'];
        $ao = $people['ao'];
        $am = $people['am'];
        $final =$this->now->copy()->subDays($age)->subMinutes(20 + ($i * 7) % 400);
        $at = fn (float $daysBefore, int $minutes = 0) => $final->copy()->subMinutes((int) round($daysBefore * 1440))->addMinutes($minutes);
        $reasons = array_keys(Pv::QUERY_REASONS);
        $reason = $reasons[$i % count($reasons)];
        $other = $reasons[($i + 3) % count($reasons)];

        [$details, $amount] = $this->fields($i);

        $voucherAt = fn (float $daysBefore) => $at($daysBefore);
        $make = function (float $daysBefore) use ($fo, $number, $type, $payee, $details, $amount, $voucherAt): Pv {
            return $this->createDemoDraft($fo, $number, $type, $payee, $details, $amount, $voucherAt($daysBefore), $daysBefore);
        };
        $send = function (Pv $pv, float $daysBefore) use ($fo, $voucherAt): Pv {
            $this->as($fo, $voucherAt($daysBefore)->addMinutes(10));

            return $this->workflow->submit($fo, $pv, $pv->lock_version);
        };
        // The Audit manager gives a sent voucher to the Audit officer a few minutes after it was sent; nobody in Audit can work on it before.
        $assign = function (Pv $pv, float $daysBefore) use ($am, $ao, $voucherAt): Pv {
            $this->as($am, $voucherAt($daysBefore)->addMinutes(15));

            return $this->workflow->assign($am, $pv, $ao->id);
        };
        $sendA = fn (Pv $pv, float $daysBefore): Pv => $assign($send($pv, $daysBefore), $daysBefore);
        $query = function (Pv $pv, float $daysBefore, string $code) use ($ao, $voucherAt): Pv {
            $this->as($ao, $voucherAt($daysBefore));

            return $this->workflow->auditQuery($ao, $pv, $code, 'Please check this voucher against the supporting documents and correct it.');
        };
        $correct = function (Pv $pv, float $daysBefore) use ($fo, $voucherAt): Pv {
            $this->as($fo, $voucherAt($daysBefore));
            $pv = $this->workflow->update($fo, $pv, $this->dataOf($pv, ' (corrected)'), $pv->lock_version);

            return $this->workflow->resubmit($fo, $pv, $pv->lock_version, 'Corrected as asked.');
        };
        $tick = function (Pv $pv, float $daysBefore) use ($ao, $voucherAt): Pv {
            $this->as($ao, $voucherAt($daysBefore));

            return $this->workflow->auditAcknowledge($ao, $pv);
        };
        $acknowledge = function (Pv $pv, float $daysBefore) use ($fo, $voucherAt): Pv {
            $this->as($fo, $voucherAt($daysBefore));

            return $this->workflow->financeAcknowledge($fo, $pv);
        };
        $void = function (Pv $pv, float $daysBefore) use ($fo, $voucherAt): Pv {
            $this->as($fo, $voucherAt($daysBefore));

            return $this->workflow->void($fo, $pv, 'Raised in error (demo data).');
        };

        match ($scenario) {
            'draft' => $make(0),
            'with_audit' => $i % 2 === 1 ? $sendA($make(0.01), 0) : $send($make(0.01), 0),
            'queried' => $query($sendA($make(1.1), 1.1), 0, $reason),
            'queried_twice' => $query($correct($query($sendA($make(6), 6), 5, $reason), 3), 0, $other),
            'resubmitted' => $correct($query($sendA($make(5), 5), 3.5, $reason), 0),
            'ticked' => $tick($sendA($make(2), 2), 0),
            'completed' => $acknowledge($tick($sendA($make(6), 6), 4), 0),
            'completed_query' => $acknowledge($tick($correct($query($sendA($make(8), 8), 6, $reason), 4), 2), 0),
            'withdrawn_ticked' => $this->withdrawThenTick($tick($sendA($make(4), 4), 3), $ao, $voucherAt(2), $voucherAt(1)),
            'withdrawn_completed' => $acknowledge($this->withdrawThenTick($tick($sendA($make(6), 6), 5), $ao, $voucherAt(4), $voucherAt(2)), 0),
            'void_draft' => $void($make(2), 0),
            'void_with_audit' => $void($send($make(3), 3), 0),
            'void_queried' => $void($query($sendA($make(3), 3), 2, $reason), 0),
        };
    }

    /** @return array{0: string, 1: string} the details and the amount (cedis) of demo voucher number $i */
    protected function fields(int $i): array
    {
        return [
            ['Quarterly claim', 'Supplies for the district store', 'Allowance for the period', 'Repairs and servicing', 'Training and workshop costs', 'Sitting allowance, board meeting', 'Transport for field staff', 'Office running costs'][$i % 8],
            number_format(120 + (($i * 937 + 311) % 24800) + (($i * 37) % 100) / 100, 2, '.', ''),
        ];
    }

    /** A demo voucher saved as a draft by the finance officer at $when ($daysBefore only moves the PV date back a little). */
    protected function createDemoDraft(User $fo, string $number, array $type, array $payee, string $details, string $amount, Carbon $when, float $daysBefore): Pv
    {
        $this->as($fo, $when);

        return $this->workflow->createDraft($fo, [
            'pv_date' => $when->copy()->subDays(1 + ($daysBefore > 3 ? 1 : 0))->toDateString(),
            'pv_no' => $number,
            'category_id' => $type['category'],
            'type_id' => $type['type'],
            'details' => $details,
            'amount' => $amount,
            'remarks' => null,
        ] + $payee);
    }

    /** The batch the $nth voucher of a scenario goes into, or null when it is made on its own. */
    protected function batchKind(string $scenario, int $nth): ?string
    {
        foreach (self::BATCH_SLOTS[$scenario] ?? [] as [$skip, $take, $kind]) {
            if ($nth >= $skip && $nth < $skip + $take) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * One demo batch, played through the real services with the clock set back: the finance officer builds and sends it, the audit manager
     * assigns it (a whole batch to one officer, or split across two), the officers tick, and for the completed one Finance acknowledges all.
     *
     * @param  array<string, User>  $people
     * @param  list<array{scenario: string, number: string, type: array<string, int>, payee: array<string, mixed>, i: int}>  $members
     */
    protected function makeBatch(array $people, string $kind, array $members): void
    {
        [$fo, $ao, $ao2, $am] = [$people['fo'], $people['ao'], $people['ao2'], $people['am']];
        $anchor = ['draft' => 5, 'awaiting' => 8, 'progress' => 5, 'split' => 3, 'complete' => 45][$kind];
        $title = ['draft' => 'Draft batch', 'awaiting' => 'Awaiting assignment batch', 'progress' => 'In progress batch', 'split' => 'Split batch', 'complete' => 'Completed batch'][$kind].self::SUPPLIER_SUFFIX;
        $t0 = $this->now->copy()->subDays($anchor)->subMinutes(240);

        $vouchers = [];

        foreach ($members as $k => $member) {
            [$details, $amount] = $this->fields($member['i']);
            $vouchers[$k] = $this->createDemoDraft($fo, $member['number'], $member['type'], $member['payee'], $details, $amount, $t0->copy()->subMinutes(60 - $k * 5), $anchor);
        }

        $this->as($fo, $t0->copy()->subMinutes(20));
        $batch = $this->batches->create($fo, array_map(fn (Pv $pv) => $pv->id, $vouchers), $title);
        $this->batchCount++;

        if ($kind === 'draft') {
            return;
        }

        $this->as($fo, $t0);
        $this->batches->send($fo, $batch);

        if ($kind === 'awaiting') {
            return;
        }

        $this->as($am, $t0->copy()->addMinutes(45));
        $tick = fn (User $officer, int $k) => [$this->as($officer, $t0->copy()->addDay()->addMinutes($k * 3)), $this->workflow->auditAcknowledge($officer, $vouchers[$k])];
        $ticked = array_keys(array_filter($members, fn (array $m) => $m['scenario'] === 'ticked'));
        $waiting = array_keys(array_filter($members, fn (array $m) => $m['scenario'] !== 'ticked'));

        if ($kind === 'progress' || $kind === 'complete') {
            $this->batches->assignBatch($am, $batch, $ao->id, 'Demo assignment');
        }

        if ($kind === 'progress') {
            foreach ($ticked as $k) {
                $tick($ao, $k);
            }
        }

        if ($kind === 'split') {
            // Each officer gets one voucher they have already ticked and one still waiting for them.
            $this->batches->assignVouchers($am, [$vouchers[$ticked[0]]->id, $vouchers[$waiting[0]]->id], $ao->id, 'Demo assignment');
            $this->as($am, $t0->copy()->addMinutes(50));
            $this->batches->assignVouchers($am, [$vouchers[$ticked[1]]->id, $vouchers[$waiting[1]]->id], $ao2->id, 'Demo assignment');
            $tick($ao, $ticked[0]);
            $tick($ao2, $ticked[1]);
        }

        if ($kind === 'complete') {
            $this->as($ao, $t0->copy()->addDay());
            $this->batches->acknowledgeRemaining($ao, $batch);
            $this->as($fo, $t0->copy()->addDays(2));
            $this->batches->financeAcknowledgeAll($fo, $batch);
        }
    }

    /** The Audit officer withdraws a tick, then ticks again after looking once more. */
    protected function withdrawThenTick(Pv $pv, User $ao, Carbon $withdrawAt, Carbon $tickAt): Pv
    {
        $this->as($ao, $withdrawAt);
        $pv = $this->workflow->withdrawTick($ao, $pv, 'The amount needs checking against the invoice before it is acknowledged.');
        $this->as($ao, $tickAt);

        return $this->workflow->auditAcknowledge($ao, $pv);
    }

    /** The fields a voucher is saved with (to correct it). @return array<string, mixed> */
    protected function dataOf(Pv $pv, string $addToDetails = ''): array
    {
        return [
            'pv_date' => $pv->pv_date->toDateString(),
            'pv_no' => $pv->pv_no,
            'payee_kind' => $pv->payee_kind,
            'payee_employee_id' => $pv->payee_employee_id,
            'payee_district_id' => $pv->payee_district_id,
            'supplier_id' => $pv->supplier_id,
            'category_id' => $pv->category_id,
            'type_id' => $pv->type_id,
            'details' => $pv->details.$addToDetails,
            'amount' => Money::input($pv->amount),
            'remarks' => $pv->remarks,
        ];
    }

    /** Sets the clock back and who is signed in, so the service writes the right actor and time. */
    protected function as(User $user, Carbon $when): void
    {
        Carbon::setTestNow($when);
        Auth::setUser($user);
    }

    // ------------------------------------------------------------------ removal

    /**
     * Puts the batch-number counters of the locations the demo run used back to what the REMAINING batches say (the highest number in
     * use that year, or no counter row at all), so a real batch made afterwards gets the number it would have had without the demo.
     *
     * @param  list<string>  $units
     */
    protected function restoreBatchCounters(array $units): void
    {
        foreach ($units as $unit) {
            foreach (DB::table('fin_batch_counters')->where('unit', $unit)->get() as $counter) {
                $highest = 0;

                foreach (FinPvBatch::query()->where('batch_no', 'like', '%-'.$counter->year.'-%')->pluck('batch_no') as $number) {
                    $batch = FinPvBatch::query()->where('batch_no', $number)->first(['region_id', 'district_id']);

                    if ($batch && FinanceBooksVersion::unitKey(['region_id' => $batch->region_id, 'district_id' => $batch->district_id]) === $unit && preg_match('/-(\d+)$/', $number, $m)) {
                        $highest = max($highest, (int) $m[1]);
                    }
                }

                $highest === 0
                    ? DB::table('fin_batch_counters')->where('id', $counter->id)->delete()
                    : DB::table('fin_batch_counters')->where('id', $counter->id)->update(['last_number' => $highest]);
            }
        }
    }

    /**
     * Deletes ONLY the demo rows. Returns what was removed.
     *
     * @param  callable(string): void|null  $say
     * @return array{vouchers: int, suppliers: int, suppliers_kept: int, catalogue: int, accounts: int, staff: int}
     */
    public function remove(?callable $say = null): array
    {
        $this->assertAllowed(false);
        $say ??= fn (string $line) => null;

        $summary = ['vouchers' => 0, 'batches' => 0, 'suppliers' => 0, 'suppliers_kept' => 0, 'catalogue' => 0, 'accounts' => 0, 'staff' => 0];
        $userIds = User::query()->where('staff_id', 'like', self::PREFIX.'%')->pluck('id')->all();

        DB::transaction(function () use (&$summary, $userIds, $say) {
            // 1. Vouchers and everything that hangs off them (direct deletes: the event model refuses updates and deletes).
            $pvIds = Pv::query()->where('pv_no_key', 'like', self::PREFIX.'%')->pluck('id')->all();

            // The demo batches (made by a demo account) and their trail, before the vouchers: the trail points at the vouchers.
            $batchIds = FinPvBatch::query()->whereIn('created_by', $userIds)->pluck('id')->all();
            $units = FinPvBatch::query()->whereIn('id', $batchIds)->get(['region_id', 'district_id'])->map(fn (FinPvBatch $batch) => FinanceBooksVersion::unitKey(['region_id' => $batch->region_id, 'district_id' => $batch->district_id]))->unique()->all();

            foreach (array_chunk($batchIds, 500) as $chunk) {
                DB::table('fin_batch_events')->whereIn('batch_id', $chunk)->delete();
            }

            foreach (array_chunk($pvIds, 500) as $chunk) {
                $files = DB::table('fin_pv_attachments')->whereIn('pv_id', $chunk)->pluck('path')->all();
                DB::afterCommit(fn () => array_map(fn (string $path) => app(PvAttachmentService::class)->deleteFile($path), $files));

                DB::table('fin_pv_attachments')->whereIn('pv_id', $chunk)->delete();
                DB::table('fin_pv_events')->whereIn('pv_id', $chunk)->delete();
                DB::table('fin_reminder_states')->whereIn('pv_id', $chunk)->delete();
                DB::table('fin_pvs')->whereIn('id', $chunk)->delete();
            }

            foreach (array_chunk($batchIds, 500) as $chunk) {
                $summary['batches'] += DB::table('fin_pv_batches')->whereIn('id', $chunk)->delete();
            }

            $this->restoreBatchCounters($units);

            $summary['vouchers'] = count($pvIds);
            $say("Removed {$summary['vouchers']} demo vouchers and {$summary['batches']} demo batches.");

            // 2. Demo suppliers no other voucher uses.
            $used = DB::table('fin_pvs')->whereNotNull('supplier_id')->select('supplier_id');
            $kept = DB::table('fin_suppliers')->where('name', 'like', '%'.self::SUPPLIER_SUFFIX)->whereIn('id', $used)->count();
            $summary['suppliers'] = DB::table('fin_suppliers')->where('name', 'like', '%'.self::SUPPLIER_SUFFIX)->whereNotIn('id', $used)->delete();
            $summary['suppliers_kept'] = $kept;

            // 3. Demo catalogue rows: location-owned items named "... (Demo)" (not used by another voucher) and the overrides the demo accounts made.
            $typesInUse = DB::table('fin_pvs')->select('type_id');
            $catsInUse = DB::table('fin_pvs')->select('category_id');
            $summary['catalogue'] += DB::table('fin_catalog_overrides')->whereIn('updated_by', $userIds)->delete();
            $summary['catalogue'] += DB::table('fin_pv_types')->whereNotNull('unit')->where('name', 'like', '%'.self::SUPPLIER_SUFFIX)->whereNotIn('id', $typesInUse)->delete();
            $summary['catalogue'] += DB::table('fin_pv_categories')->whereNotNull('unit')->where('name', 'like', '%'.self::SUPPLIER_SUFFIX)->whereNotIn('id', $catsInUse)
                ->whereNotIn('id', DB::table('fin_pv_types')->select('category_id'))->delete();

            // 4. Demo accounts, their notices, the audit rows they wrote, and the demo staff records.
            $emails = DB::table('users')->whereIn('id', $userIds)->pluck('email')->all();
            DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $userIds)->delete();
            DB::table('audit_logs')->whereIn('user_id', $userIds)->delete();
            DB::table('user_roles')->whereIn('user_id', $userIds)->delete();

            if (Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
            }

            $summary['accounts'] = DB::table('users')->whereIn('id', $userIds)->delete();
            $summary['staff'] = DB::table('employees')->where('staff_id', 'like', self::PREFIX.'%')->delete();
            $say("Removed {$summary['accounts']} demo accounts and {$summary['staff']} demo staff records.");
        });

        FinanceBooksVersion::touchAll();

        return $summary;
    }
}
