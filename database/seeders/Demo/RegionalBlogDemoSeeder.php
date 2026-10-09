<?php

namespace Database\Seeders\Demo;

use App\Models\BlogPost;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Blog\BlogPostService;
use App\Services\Blog\BlogVisibility;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo articles for the Regional Blog, for the Accra West region only.
 *
 * Not part of DatabaseSeeder. Run it after the normal seed (and after DemoDataSeeder if you want its Accra West staff and
 * districts, which this seeder reuses; whatever is missing is added):
 *
 *   php artisan db:seed
 *   php artisan db:seed --class=Database\\Seeders\\Demo\\RegionalBlogDemoSeeder
 *
 * Articles are created ONLY through BlogPostService (create, publish, setPinned) acting as the region's PR officer, never
 * inserted into blog_posts, so authorship, the audit trail and the cover files are exactly what the application produces.
 * History is back-dated by moving the clock (Carbon::setTestNow) to each action's moment, restored in a finally.
 *
 * Idempotent: people are keyed on staff id, articles on region + title (an existing article is left exactly as it is, and
 * no cover is made for it). Deterministic: fixed text, dates relative to now(). Refuses to run in production.
 * Covers are 1200x675 placeholders drawn with GD; without GD the covers are skipped and the summary says so.
 */
class RegionalBlogDemoSeeder extends Seeder
{
    protected const REGION_NAME = 'Accra West';

    protected const OFFICE_NAME = 'Accra West Regional Office';

    protected const DISTRICTS = ['Darkuman', 'Sowutuom', 'Amasaman', 'Keneshie', 'Odorkor'];

    protected const PASSWORD = '12345';

    protected const EMAIL_DOMAIN = 'gwcl-demo.test';

    /** staff id, full name, gender, district index (null = regional office), job title, department, roles */
    protected const PEOPLE = [
        ['BLA001', 'Araba Mensah-Quaye', 'Female', null, 'Public Relations Officer', 'Public Relations', ['pr_officer', 'employee']],
        ['BLA101', 'Kofi Annan-Tetteh', 'Male', 1, 'Customer Service Officer', 'Commercial', ['employee']],
        ['BLA102', 'Efua Darko-Ofori', 'Female', 4, 'Customer Service Officer', 'Commercial', ['employee']],
    ];

    protected const SUPER_ADMIN_STAFF_ID = 'BLSA01';

    protected const NO_REGION_STAFF_ID = 'BLNR01';

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

    protected User $noRegion;

    protected Carbon $now;

    protected bool $gd = false;

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    protected array $loginRows = [];

    /** @var array<string, int|string> */
    protected array $counts = ['created' => 0, 'existing' => 0, 'covers' => 0];

    /** @var list<string> */
    protected array $checks = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('The Regional Blog demo seeder never runs in production.');

            return;
        }

        if (! $this->prerequisitesMet()) {
            return;
        }

        $this->now = Carbon::now();
        $this->gd = extension_loaded('gd') && function_exists('imagecreatetruecolor') && function_exists('imagejpeg');

        $previousMailer = config('mail.default');
        config(['mail.default' => 'array']); // EmployeeObserver sends an invite for each new employee

        try {
            $this->seedLocations();
            $this->seedLookups();
            $this->seedPeople();
            $this->seedArticles();
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
        foreach (['regions', 'districts', 'departments', 'job_titles', 'employees', 'users', 'roles', 'blog_posts', 'audit_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->command?->error("Table [{$table}] is missing: run `php artisan migrate` first.");

                return false;
            }
        }

        $missing = collect(['super_admin', 'pr_officer', 'employee'])->diff(Role::query()->pluck('name'));

        if ($missing->isNotEmpty()) {
            $this->command?->error('Missing roles ('.$missing->join(', ').'): run `php artisan db:seed` first.');

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------ locations, lookups, people

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

            $this->loginRows[] = [$staffId, implode(', ', $roles), $employee->full_name, self::REGION_NAME];
        }

        // A super admin is not a member of staff: no employee record, sees and writes every region.
        $this->superAdmin = $this->loginWithoutEmployee(self::SUPER_ADMIN_STAFF_ID, 'Demo Blog Super Administrator', 'demo.blog.superadmin@'.self::EMAIL_DOMAIN, ['super_admin'], $roleIds);
        $this->loginRows[] = [self::SUPER_ADMIN_STAFF_ID, 'super_admin', $this->superAdmin->full_name, 'every region'];

        // A login with no employee record: the blog tells them their account is not linked to a region.
        $this->noRegion = $this->loginWithoutEmployee(self::NO_REGION_STAFF_ID, 'Demo Unlinked Account', 'demo.blog.noregion@'.self::EMAIL_DOMAIN, ['employee'], $roleIds);
        $this->loginRows[] = [self::NO_REGION_STAFF_ID, 'employee (no employee record)', $this->noRegion->full_name, 'none: shows the "no region" warning'];
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

    protected function loginWithoutEmployee(string $staffId, string $name, string $email, array $roles, $roleIds): User
    {
        $user = User::query()->where('staff_id', $staffId)->first()
            ?? User::query()->create([
                'staff_id' => $staffId,
                'full_name' => $name,
                'email' => $email,
                'password' => Hash::make(self::PASSWORD),
                'is_active' => true,
                'must_change_password' => false,
            ]);

        $user->roles()->syncWithoutDetaching($roleIds->only($roles)->values()->all());

        return $user->fresh();
    }

    // ------------------------------------------------------------------ articles

    protected function seedArticles(): void
    {
        $officer = $this->users['BLA001'];
        $service = app(BlogPostService::class);

        // Authorship and the audit rows (Audit reads Auth::user()) must be the PR officer's. setUser rather than login():
        // login() would migrate a session that does not exist on the command line.
        Auth::guard()->setUser($officer);

        foreach ($this->articles() as $index => $article) {
            $title = strtr($article['title'], $this->placeholders($article));

            if (BlogPost::query()->where('region_id', $this->region->id)->where('title', $title)->exists()) {
                $this->counts['existing']++;

                continue;
            }

            $moment = $this->moment($index, $article['published'] ?? null);
            $cover = $article['cover'] && $this->gd ? $this->makeCover($title, $article['category'], $index) : null;

            try {
                $this->at($moment, function () use ($service, $officer, $article, $title, $cover) {
                    $post = $service->create($officer, [
                        'region_id' => $this->region->id,
                        'district_id' => isset($article['district']) ? $this->districts[$article['district']]->id : null,
                        'title' => $title,
                        'category' => $article['category'],
                        'summary' => strtr($article['summary'], $this->placeholders($article)),
                        'body' => strtr($article['body'], $this->placeholders($article)),
                        'tags' => $article['tags'],
                        'event_date' => isset($article['event']) ? $this->now->copy()->addDays($article['event'])->toDateString() : null,
                        'venue' => isset($article['venue']) ? strtr($article['venue'], $this->placeholders($article)) : null,
                    ], $cover?->file, publish: $article['published'] !== null);

                    if ($article['pinned'] ?? false) {
                        $service->setPinned($post, $officer, true);
                    }
                });
            } finally {
                $cover?->discard();
            }

            $this->counts['created']++;
            $cover && $this->counts['covers']++;
        }
    }

    /** The clock for article $index: published ones that many days ago; a draft was last worked on a few days ago. */
    protected function moment(int $index, ?int $daysAgo): Carbon
    {
        $moment = $this->now->copy()->subDays($daysAgo ?? 2 + $index)->setTime(8 + ($index * 3) % 9, ($index * 17) % 60);

        // setTestNow in the future would invent history; keep it just before the real clock.
        return $moment->gte($this->now) ? $this->now->copy()->subMinutes(5) : $moment;
    }

    protected function at(Carbon $moment, callable $callback): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($moment);

        try {
            return $callback();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    protected function placeholders(array $article): array
    {
        $district = $this->districts[$article['district'] ?? 0];

        return [
            '{district}' => $district->district_name,
            '{office}' => self::OFFICE_NAME,
            '{region}' => self::REGION_NAME,
        ];
    }

    // ------------------------------------------------------------------ covers

    /** A 1200x675 JPEG placeholder in a temp file, wrapped so the service takes it like an upload. */
    protected function makeCover(string $title, string $category, int $seed): object
    {
        $palettes = [
            'activity' => [[14, 116, 144], [8, 47, 73]],
            'meeting' => [[67, 56, 202], [30, 27, 75]],
            'workshop' => [[2, 132, 199], [12, 74, 110]],
            'training' => [[13, 148, 136], [19, 78, 74]],
            'announcement' => [[217, 119, 6], [120, 53, 15]],
            'other' => [[71, 85, 105], [30, 41, 59]],
        ];
        [$top, $bottom] = $palettes[$category] ?? $palettes['other'];

        $w = 1200;
        $h = 675;
        $image = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y++) {
            $t = $y / ($h - 1);
            $color = imagecolorallocate(
                $image,
                (int) ($top[0] + ($bottom[0] - $top[0]) * $t),
                (int) ($top[1] + ($bottom[1] - $top[1]) * $t),
                (int) ($top[2] + ($bottom[2] - $top[2]) * $t),
            );
            imageline($image, 0, $y, $w, $y, $color);
        }

        // Soft circles, positions fixed by the seed so the picture is the same every run.
        imagealphablending($image, true);

        for ($i = 0; $i < 6; $i++) {
            $x = (($seed + 1) * 211 + $i * 337) % $w;
            $y = (($seed + 2) * 127 + $i * 191) % $h;
            $r = 80 + (($seed + $i) * 41) % 160;
            imagefilledellipse($image, $x, $y, $r * 2, $r * 2, imagecolorallocatealpha($image, 255, 255, 255, 112));
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 48, $h - 90, strtoupper($category).'  |  GWCL '.strtoupper(self::REGION_NAME), $white);
        imagestring($image, 4, 48, $h - 60, Str::limit($title, 90, '...'), $white);

        $path = tempnam(sys_get_temp_dir(), 'blogcover');
        imagejpeg($image, $path, 82);
        imagedestroy($image);

        return new class($path)
        {
            public UploadedFile $file;

            public function __construct(protected string $path)
            {
                $this->file = new UploadedFile($path, 'cover-'.Str::random(6).'.jpg', 'image/jpeg', null, true);
            }

            public function discard(): void
            {
                @unlink($this->path);
            }
        };
    }

    // ------------------------------------------------------------------ checks and summary

    /** Re-checks the demo against the BlogVisibility rules; throws on the first rule that does not hold. */
    protected function verify(): void
    {
        $visibility = app(BlogVisibility::class);
        $reader = $this->users['BLA101'];
        $officer = $this->users['BLA001'];

        $published = BlogPost::query()->where('region_id', $this->region->id)->published()->count();
        $all = BlogPost::query()->where('region_id', $this->region->id)->count();
        $drafts = $all - $published;

        $readerSees = $visibility->scopeFeed(BlogPost::query(), $reader)->get();
        $this->expect(
            'reader (BLA101) sees only the published articles of Accra West',
            $readerSees->count() === $published && $readerSees->every(fn (BlogPost $p) => $p->isPublished() && (int) $p->region_id === (int) $this->region->id)
        );

        $manageable = $visibility->scopeManageable(BlogPost::query(), $officer)->get();
        $this->expect('PR officer (BLA001) also sees the drafts ('.$drafts.')', $manageable->count() === $all && $manageable->where('status', BlogPost::STATUS_DRAFT)->count() === $drafts);

        $draft = BlogPost::query()->where('region_id', $this->region->id)->where('status', BlogPost::STATUS_DRAFT)->first();
        $this->expect('a reader cannot open a draft; the officer can', ! $draft || (! $visibility->canRead($reader, $draft) && $visibility->canRead($officer, $draft)));

        $this->expect('super admin (BLSA01) sees every published article of every region', $visibility->scopeFeed(BlogPost::query(), $this->superAdmin)->count() === BlogPost::query()->published()->count());

        $this->expect('the unlinked login (BLNR01) sees nothing', $visibility->scopeFeed(BlogPost::query(), $this->noRegion)->count() === 0);

        // A reader of another region cannot open an Accra West article. Only checked when another region has staff;
        // tests/Feature/Blog/RegionalBlogDemoSeederTest builds one and checks the 404 over HTTP.
        $other = Employee::query()->where('is_active', true)->whereNotNull('region_id')->where('region_id', '!=', $this->region->id)
            ->whereHas('user')->with('user')->first()?->user;

        if ($other && ($post = BlogPost::query()->where('region_id', $this->region->id)->published()->first())) {
            $this->expect('a reader in another region ('.$other->staff_id.') cannot open an Accra West article', ! $visibility->canRead($other, $post));
        } else {
            $this->checks[] = 'SKIPPED: no staff in another region exists here to try the cross-region rule (the PHPUnit test covers it)';
        }
    }

    protected function expect(string $rule, bool $ok): void
    {
        if (! $ok) {
            throw new RuntimeException('Blog demo check failed: '.$rule);
        }

        $this->checks[] = 'OK: '.$rule;
    }

    protected function printSummary(): void
    {
        if (! $this->command) {
            return;
        }

        $posts = BlogPost::query()->where('region_id', $this->region->id);
        $this->command->newLine();
        $this->command->info('Regional Blog demo data (Accra West only)');
        $this->command->table(['Staff ID', 'Role(s)', 'Name', 'Region'], array_map(fn (array $row) => [...$row], $this->loginRows));
        $this->command->line('Password for every login above: '.self::PASSWORD.' (no forced change)');

        $this->command->table(['Region', 'Articles', 'Published', 'Drafts', 'Pinned', 'With cover', 'Future event dates'], [[
            self::REGION_NAME,
            (clone $posts)->count(),
            (clone $posts)->published()->count(),
            (clone $posts)->where('status', BlogPost::STATUS_DRAFT)->count(),
            (clone $posts)->where('is_pinned', true)->count(),
            (clone $posts)->whereNotNull('cover_path')->count(),
            (clone $posts)->whereDate('event_date', '>', $this->now->toDateString())->count(),
        ]]);

        $this->command->line("This run: {$this->counts['created']} created, {$this->counts['existing']} already there, {$this->counts['covers']} covers made.");

        if (! $this->gd) {
            $this->command->warn('The GD extension is not loaded: no cover photos were made.');
        }

        foreach ($this->checks as $check) {
            $this->command->line($check);
        }
    }

    // ------------------------------------------------------------------ the articles

    /**
     * Ten published articles (published = days ago; district = index into DISTRICTS; event = days from today) and two drafts.
     * {district}, {office} and {region} are filled in from the article's district.
     *
     * @return list<array<string, mixed>>
     */
    protected function articles(): array
    {
        return [
            [
                'title' => 'Planned service interruption in {district} this weekend',
                'category' => 'announcement', 'district' => 0, 'published' => 3, 'pinned' => true, 'cover' => false, 'event' => 6,
                'venue' => '{district} District Office',
                'tags' => ['notice', 'interruption', 'pipeline'],
                'summary' => 'Water supply to parts of {district} will be off for about eight hours on Saturday while a damaged section of main is replaced.',
                'body' => <<<'MD'
## What is happening

Our crews will replace a damaged section of the **distribution main** serving parts of {district}. To do the work safely the supply has to be shut off.

- **When:** Saturday, from 6:00 am to about 2:00 pm
- **Where:** the {district} town centre and the communities along the main road
- **Who is affected:** households, schools and businesses fed from that section

## What you can do

1. Store enough water on Friday evening for drinking, cooking and sanitation.
2. Close your taps before the supply is cut so that nothing overflows when it returns.
3. Expect cloudy water for a short while after the supply is restored; let the taps run until it clears.

Tankers will serve the clinics in the area. To report a problem during the works, call the {district} District Office or the regional customer-care line.

*Thank you for your patience while we improve the network.*
MD,
            ],
            [
                'title' => 'World Water Day follow-up: school visits reach {district} pupils',
                'category' => 'activity', 'district' => 1, 'published' => 58, 'cover' => true, 'event' => -60,
                'venue' => '{district} Basic School cluster',
                'tags' => ['schools', 'outreach', 'water-day', 'conservation'],
                'summary' => 'The PR team and district volunteers spent a week in classrooms teaching pupils where their water comes from and how not to waste it.',
                'body' => <<<'MD'
## A week in the classroom

Following this year's **World Water Day**, the regional PR team and volunteers from the {district} District Office visited five schools to talk to pupils about the water they drink every day. More than 900 pupils from primary 4 to JHS 3 took part.

### What we covered

- Where treated water comes from, and how it travels to the tap
- Simple ways to save water at home: closing taps, fixing dripping pipes, reusing rinse water
- Why we must never tamper with meters or pipes
- How to report a leak (and why a child's report is just as welcome as an adult's)

### Highlights

The quiz on the last day was the most popular part. Teams answered questions on the water cycle and water-saving habits, and **every pupil** went home with an activity sheet to share with their family. One class asked for a standing "water prefect" in each classroom to remind others to close the taps; the headteachers liked the idea.

### What teachers told us

> "The children came home asking us to repair the dripping tap in the kitchen."

We will return next term with a short clean-water pledge for each class to sign. To learn more about the global campaign, see [UN-Water](https://www.unwater.org/).

### Thank you

Thank you to the headteachers and staff of the schools involved and to the colleagues who gave up their time. If your district would like a visit, contact the regional PR office.
MD,
            ],
            [
                'title' => 'Customer-care workshop for district frontline staff',
                'category' => 'workshop', 'district' => 3, 'published' => 44, 'cover' => false, 'event' => -47,
                'venue' => '{office}, conference room',
                'tags' => ['customer-care', 'workshop', 'frontline'],
                'summary' => 'Cashiers, customer-service officers and complaint handlers practised handling difficult conversations and logging complaints the same way everywhere.',
                'body' => <<<'MD'
## Better conversations at the counter

Forty frontline staff from all five districts met at the {office} for a one-day workshop on customer care.

**Topics**

- Greeting and listening: the first minute decides the rest of the visit
- Explaining a bill without jargon
- Handling an angry customer calmly
- Recording every complaint in the same way, so that nothing is lost between the counter and the district manager

**Group exercise.** Staff acted out three real situations (an estimated bill, a disconnection notice, a burst pipe outside a shop) and the group discussed what went well.

**Agreed actions**

1. Every complaint gets a reference number that is given to the customer.
2. Customers are told when to expect an answer.
3. Districts will share their most common complaints each month.

Certificates were presented at the end of the day. Follow-up refreshers will be arranged by the district managers.
MD,
            ],
            [
                'title' => 'Leak-detection and repair training for district crews',
                'category' => 'training', 'district' => 4, 'published' => 29, 'pinned' => true, 'cover' => true, 'event' => -33,
                'venue' => '{district} depot and Accra West training yard',
                'tags' => ['leaks', 'training', 'non-revenue-water', 'safety', 'distribution'],
                'summary' => 'Two groups of plumbers and technicians took a four-day course on finding hidden leaks, repairing mains correctly and recording what they find.',
                'body' => <<<'MD'
# Finding leaks before customers do

Water that leaks out of the pipes is water we have treated and paid to produce but never sell. To help our crews find it sooner, the Distribution unit organised a **four-day practical course** at the {district} depot and the regional training yard. Twenty-four plumbers, technicians and supervisors attended in two groups.

## Day 1: how a network behaves

Trainers explained how pressure changes along a line, why night flow is a useful clue, and how to read a district meter. Participants then traced a small test network on the yard.

## Day 2: listening for leaks

Crews learned to use **listening sticks** and electronic loggers on valves, hydrants and house connections. In the afternoon they practised on a pipe with a deliberately placed leak and had to pin-point it to within a metre.

## Day 3: repairs done properly

- Cutting out a damaged section and fitting a coupler
- Restoring the trench so that the road is not damaged again
- Disinfecting the repair before returning it to service
- Wearing the right protective equipment and putting up barriers and signs

## Day 4: recording and reporting

A repair that is not recorded cannot be counted. Participants practised filling in the leak-repair form, including the **location, pipe size, cause and time to repair**, and agreed to send the forms to the district office every Friday.

## What happens next

1. Each district will name one "leak champion" to coach colleagues.
2. The training team will visit each depot within three months to see the methods in use.
3. Repairs will be reported in the monthly district report.

Our thanks to the trainers and to the Distribution managers who released their staff. For background on reducing water losses, see the [Ghana Water Limited website](https://www.gwcl.com.gh).
MD,
            ],
            [
                'title' => 'Staff durbar and long-service awards at the regional office',
                'category' => 'other', 'district' => null, 'published' => 21, 'cover' => false, 'event' => -22,
                'venue' => '{office}, forecourt',
                'tags' => ['staff', 'awards', 'durbar'],
                'summary' => 'Colleagues from every district gathered to hear the year-to-date results and to honour staff with long service.',
                'body' => <<<'MD'
## Together at the forecourt

The regional management met staff from the regional office and all districts for the half-year durbar. The Regional Chief Manager reviewed the results so far, thanked staff for their work, and urged everyone to keep the **customer** at the centre of what we do.

### Long-service awards

Staff who have served **10, 20 and 30 years** were called up to receive certificates. The longest-serving member of staff received a standing ovation.

### Photos

Photos by the PR team.

<div style="color:red" onclick="alert('hello')">Click here for the photo gallery</div>
<script>alert('This script should never run')</script>

*(The two lines above are raw HTML left in this demo article on purpose: the blog strips raw HTML, so they should show as plain text or not at all.)*

### Next

The next durbar is planned for the end of the year. Suggestions for the programme can be sent to the PR office.
MD,
            ],
            [
                'title' => '{district} stakeholder meeting on the road expansion and pipeline relocation',
                'category' => 'meeting', 'district' => 2, 'published' => 36, 'cover' => true, 'event' => -38,
                'venue' => '{district} Municipal Assembly hall',
                'tags' => ['stakeholders', 'road-works', 'pipelines', 'community'],
                'summary' => 'Utility companies, the assembly and community leaders agreed on how and when pipes will be moved so that the road works cause the least disruption to supply.',
                'body' => <<<'MD'
## Why we met

Road expansion works in {district} will cross several of our mains. To avoid damage and unplanned interruptions, the regional office called a meeting with the municipal assembly, the road contractor, the power and telecom companies, and the leaders of the affected communities.

## Who attended

- The Municipal Chief Executive's representative and the assembly's works engineer
- The road contractor's site team
- Opinion leaders and market-women's representatives
- The Regional Chief Manager, the District Manager and the Distribution and Commercial managers of GWCL

## Points agreed

1. **Joint survey.** A joint walk-through of the route will mark every GWCL pipe before excavation begins.
2. **Relocation schedule.** Pipes will be moved in short sections, with the heaviest work done at night and at weekends where possible.
3. **Notice to customers.** Every planned interruption will be announced at least 48 hours ahead by SMS, radio and notices at the district office.
4. **Single contact.** Each side named one focal person so that a pipe struck by accident is reported in minutes.
5. **Temporary supply.** Tankers will be positioned for the clinics and schools along the route.

## Concerns raised by the community

Traders asked that the works do not block market access on market days, and residents asked for clear signs around open trenches. The contractor agreed to both.

## Next steps

A follow-up meeting will be held after the joint survey. Minutes will be shared with all attendees by the end of the week. Updates will be posted on this blog.
MD,
            ],
            [
                'title' => 'Open day at the {district} District Office: bring your bill questions',
                'category' => 'activity', 'district' => 1, 'published' => 6, 'cover' => true, 'event' => 12,
                'venue' => 'Customer hall, {district} District Office',
                'tags' => ['open-day', 'customers', 'billing'],
                'summary' => 'A customer open day with a help desk for billing questions, meter checks and new connections.',
                'body' => <<<'MD'
## You are invited

The {district} District Office will hold an **open day** for customers. Staff will be available to:

- explain bills and payment options
- check meters and answer questions about readings
- take applications for new connections
- receive complaints and give a reference number

**Refreshments will be served** and the PR team will give out leaflets on saving water.

Colleagues are encouraged to tell family, neighbours and friends. Please come with your latest bill or account number if you have one.
MD,
            ],
            [
                'title' => 'Meter-reading refresher workshop',
                'category' => 'workshop', 'district' => 4, 'published' => 52, 'cover' => true, 'event' => -55,
                'venue' => '{district} District Office, training room',
                'tags' => ['meters', 'meter-reading', 'workshop'],
                'summary' => 'Meter readers refreshed how to read, record and report faults, and discussed the routes that are hardest to cover.',
                'body' => <<<'MD'
## Back to basics

Meter readers from the districts spent a day at {district} refreshing the skills behind accurate bills.

### Covered

- Reading different meter types correctly, including meters that are hard to see
- What to do when a meter is **inaccessible**, damaged or missing, rather than estimating
- Taking a photo of the reading and the meter number where the handset allows
- Reporting suspected illegal connections without confronting anyone

### From the field

Readers shared the routes that are difficult (locked compounds, dogs, flooded meter chambers) and agreed to flag them to the district office so that supervisors can plan better.

### Reminder

An accurate reading is the first step to an accurate bill. When in doubt, **report it**; do not guess.
MD,
            ],
            [
                'title' => 'Safety toolbox talk: what we check before every excavation',
                'category' => 'training', 'district' => 0, 'published' => 10, 'cover' => true, 'event' => -11,
                'venue' => '{district} depot',
                'tags' => ['safety', 'toolbox-talk', 'excavation'],
                'summary' => 'A short morning talk reminded crews of the checks that keep people safe around trenches.',
                'body' => <<<'MD'
## Ten minutes that matter

Before going out, the {district} crews gathered for a **toolbox talk** led by the district safety focal person.

**The checklist**

1. Have the other utilities' pipes and cables been marked?
2. Is the trench barriered, lit and signed?
3. Is the trench wall supported or sloped if it is deeper than a metre?
4. Is everyone wearing boots, a vest, gloves and a helmet?
5. Does somebody know where you are and when you will be back?

Report any incident or near miss on the same day. Reporting a near miss is not a confession; it is how we stop the next accident.
MD,
            ],
            [
                'title' => 'Quarterly regional management meeting: preparing for the new quarter',
                'category' => 'meeting', 'district' => null, 'published' => 8, 'cover' => false, 'event' => 20,
                'venue' => '{office}, boardroom',
                'tags' => ['management', 'planning'],
                'summary' => 'District and departmental managers will meet to review the quarter and agree priorities for the next.',
                'body' => <<<'MD'
## Date and agenda

District Managers and departmental managers will meet at the {office} to:

- review performance for the quarter
- agree priorities and targets for the next quarter
- discuss staff welfare, training needs and safety

Papers will be circulated by the Regional Chief Manager's secretariat one week before.
MD,
            ],
            [
                'title' => 'Draft: billing-queries clinic for the {district} area',
                'category' => 'workshop', 'district' => 3, 'published' => null, 'cover' => false, 'event' => 30,
                'venue' => '{district} District Office',
                'tags' => ['billing', 'customers'],
                'summary' => 'Planning notes for a clinic where customers can bring billing questions.',
                'body' => <<<'MD'
## Plan (still being agreed)

- Date to be confirmed with the District Manager
- Who will staff the help desk?
- Posters for the district office and churches

*Not for readers yet.*
MD,
            ],
            [
                'title' => 'Draft: pipeline rehabilitation update for {district}',
                'category' => 'announcement', 'district' => 2, 'published' => null, 'cover' => false,
                'tags' => ['pipeline', 'update'],
                'summary' => 'An update on the rehabilitation works, waiting for figures from Distribution.',
                'body' => <<<'MD'
## Update

Works started on the first section. **Figures to come from Distribution before this is published.**

1. Section 1: in progress
2. Section 2: planned
MD,
            ],
        ];
    }
}
