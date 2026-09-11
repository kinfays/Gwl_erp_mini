# CLAUDE.md

Guidance for Claude Code (and any other agent) working in this repository.

## What this is

A Laravel-based internal ERP portal (staff_id + password login) covering seven
modules: **UAC** (user access control), **Staff** (HR directory), **Leave**
(leave requests/balances), **Letters** (document routing), **Visitors**
(kiosk check-in/out), **Assets** (ICT inventory), and **Transport** (fleet
management). See `APP_DOCUMENTATION.md` and `docs/` for a functional
walkthrough of each module — this file is about how the code is put
together, not what it does for end users.

`docs/` is split by module and may drift; the Assets and Transport modules
in particular were added after `APP_DOCUMENTATION.md` was last updated, so
verify against the actual code (routes/models/migrations) before trusting
docs on those two.

## Tech stack

- **PHP 8.3+**, **Laravel 13**
- **Livewire 4.2** — primary interactive UI layer (not a JSON API + SPA frontend)
- **Laravel Breeze** — auth scaffolding (login/register/password reset views + controllers)
- **Maatwebsite/Excel** — xlsx import and export
- **Dompdf** — PDF export (visitor logs, transport reports)
- **Blade + Alpine.js + Tailwind CSS + Vite** — frontend
- **SQLite** by default (`database/database.sqlite`); swappable via `.env`
- **Pest is NOT used** — tests are plain PHPUnit (`php artisan test` / `phpunit`)

## Folder structure

```
app/
  Events/Transport/          Domain events (currently transport-only)
  Exports/{Leave,Staff,Transport,Visitors}/   Maatwebsite Excel export classes, one per report
  Http/
    Controllers/             Thin controllers; grouped by module (Leave/, Staff/, Transport/, Visitors/, Assets/, Api/, Auth/)
    Controllers/Concerns/    EnforcesModuleAccess trait for controller-level module checks
    Middleware/              CheckModuleAccess, CheckRole, CheckPermission, EnsureUserIsActive
    Requests/                Form requests, grouped by module (Transport/, Uac/, Auth/)
  Listeners/Transport/       Event listeners (notify managers of issues/maintenance/expiry)
  Livewire/                  Interactive UI components, grouped by module (Leave/, Staff/, Letters/, Transport/, Assets/, Visitors/, Uac/, Notifications/)
  Livewire/Concerns/         EnforcesModuleAccess trait for Livewire-level module checks (separate from the controller one above)
  Mail/                      Mailables (leave submitted/recommended/approved/denied)
  Models/                    Eloquent models, flat under app/Models (Concerns/ holds shared traits like HasUuid)
  Notifications/             Laravel notification classes (invite user, general bell, leave recommended)
  Observers/                 EmployeeObserver (syncs Employee -> User), MileageLogObserver
  Policies/                  VehiclePolicy (the only policy currently defined)
  Providers/                 AppServiceProvider (forces HTTPS scheme, etc.)
  Repositories/Transport/    VehicleRepository (only module using an explicit repository)
  Services/                  Business logic layer, grouped by module: Leave/, Letters/, Staff/, Transport/, Import/, plus a flat ReportsService
  Support/                   Cross-cutting helpers: ErpNavigation (nav/module visibility), Audit (audit-log helper), PasswordRules, UserProfilePayload
  View/Components/           Blade layout components (AppLayout, ErpLayout, GuestLayout)
  helpers.php                Global helper functions, autoloaded via composer.json "files"

routes/
  web.php                    All authenticated UI routes, grouped per module with prefix()/name()/middleware()
  api.php                    Bearer-token JSON endpoints (agent telemetry, driver mobile app) — NOT Sanctum, a raw api_token column check
  auth.php                   Breeze auth routes, required from web.php
  console.php                Artisan commands + Schedule:: definitions (visitor auto-checkout, transport expiry checks)

database/
  migrations/                Chronological; see "Migration conventions" below
  factories/                 Only exist for Transport-module models + User (older modules have no factories — build data manually in tests)
  seeders/                   Role/permission/module-access seeders per module + DatabaseSeeder orchestrating them

resources/views/
  {module}/                  Thin Blade wrapper views, usually just <livewire:.../> or a couple of lines — real UI lives in resources/views/livewire/{module}/
  livewire/{module}/         The actual Livewire component templates
  layouts/                   app.blade.php (Breeze default), erp.blade.php (the real app chrome/sidebar), guest.blade.php, navigation.blade.php
  components/                Shared Blade components (form/, global/, uac/, leave/)
  emails/                    Mail-notification Blade templates

config/gwl.php, config/gwcl.php   App-specific settings (see "Custom config" below) — NOT stock Laravel config
tests/Feature/{module}/      Feature tests, grouped per module; RefreshDatabase + Livewire::test() are the norm
tests/Unit/                  Currently empty/unused in practice — most coverage is Feature-level
```

## Key conventions

### Naming
- Tables and columns: `snake_case`. Models: singular `StudlyCase` matching Laravel defaults, with a few explicit `protected $table` overrides (`AgentReport` -> `agent_reports`... actually inferred correctly, but `IctAsset` -> `ict_assets`, `ModuleAccess` -> `module_access` are set explicitly since they don't pluralize cleanly).
- Route names: `{module}.{action}`, e.g. `staff.toggle-status`, `leave.export.approved.excel`, `uac.users.invite`. Route groups set the prefix and `name()` once per module in `web.php`.
- Permissions: `{module}.{action}` slugs, e.g. `staff.manage_departments`, `transport.view_reports`. Defined as rows in the `permissions` table (module column = one of `Permission::MODULES`), not as code constants beyond the module list itself.
- Middleware aliases used in routes: `auth`, `active` (EnsureUserIsActive), `module:{slug}`, `role:{role1,role2}`, `permission:{perm1,perm2}` (OR semantics — any one match passes).

### Authorization is layered, not single-source
1. Route middleware (`module:`, `role:`, `permission:`) — first line of defense.
2. Controller-level re-check via `App\Http\Controllers\Concerns\EnforcesModuleAccess` (some controllers only).
3. Livewire component re-check via `App\Livewire\Concerns\EnforcesModuleAccess` (a **separate, duplicate trait** — don't assume changing one changes both).
4. `super_admin` bypasses every check at every layer.
5. ICT Team users without admin/super_admin are region-scoped in UAC and Assets — look for `isRegionScopedIct()` helpers when touching those controllers.

When adding a new protected screen, replicate this pattern rather than relying on route middleware alone — several existing controllers do the belt-and-suspenders check deliberately.

### Service layer pattern
Controllers and Livewire components stay thin and delegate to `app/Services/{Module}/*`. Follow the existing per-module split:
- `Services/Leave/` — `LeaveApprovalChainResolver`, `LeaveWorkflowService`, `LeaveBalanceService`, `LeaveEntitlementService`, `LeaveNotificationService`, `WorkingDaysCalculator` — one class per concern, not one god-service.
- `Services/Letters/LetterWorkflowService` — routing/dispatch/remark logic.
- `Services/Staff/EmployeeDirectory` — the canonical place for role-scoped employee visibility queries (used by both StaffController and UAC's employee search).
- `Services/Transport/TransportService`, `TransportNotificationService`.
- `Services/Import/DataImportService` — shared xlsx import/preview/validate pipeline for both the UAC and Staff import screens (see `ImportController`).
- Flat `Services/ReportsService` — date-range resolution + payload building for transport reports (PDF/Excel).

If you're adding meaningful business logic, put it in a new or existing service class, not directly in a controller/Livewire method.

### Audit logging
Any mutating action a human should be able to trace back later calls `AuditLog::record(...)` (static helper on the model) or the `App\Support\Audit::log(...)` wrapper — both write to `audit_logs`. Follow this pattern for new create/update/delete/export endpoints; it's checked for in several existing tests indirectly via the UAC audit log screen.

### Migration conventions
- Never edit an existing migration once merged — add a new `add_x_to_y_table` / `create_x_table` migration instead. The history is full of these (`add_full_name_to_users_table`, `add_must_change_password_to_users_table`, etc.).
- New/altering migrations defensively check `Schema::hasTable()` / `Schema::hasColumn()` before acting, so they're safe to re-run against a partially-migrated DB.
- Foreign keys are explicit about delete behavior: `restrictOnDelete()` for people/lookup data that shouldn't silently vanish, `cascadeOnDelete()` for child/detail rows, `nullOnDelete()` for optional links.
- Granting a role access to a new module, or adding a new permission, is done by data-seeding **inside a migration** (`DB::table('permissions')->updateOrInsert(...)`, `DB::table('module_access')->updateOrInsert(...)`), not only in `database/seeders`. Follow this pattern so a fresh `migrate` and an existing `migrate` on a running DB end up in the same state.
- UUIDs: only the Transport-module models (`Vehicle`, `MileageLog`, `VehicleIssue`, `MaintenanceRecord`, `VehicleExpense`, `VehicleAssignmentHistory`) use a `uuid` column via the `App\Models\Concerns\HasUuid` trait, and `Vehicle` uses `uuid` as its route-key (`getRouteKeyName()`). Everything else uses the numeric `id`.
- Soft deletes: only `Vehicle` uses `SoftDeletes`. Nothing else does — don't assume `deleted_at` exists elsewhere.

### Custom config
`config/gwl.php` and `config/gwcl.php` hold app-specific env-driven settings (auto-checkout time, leave carry-over expiry window, import failure threshold, etc.) — these are **not** stock Laravel config files, they were added for this app. `gwcl.php` currently exists mostly as a legacy/fallback alias for one `gwl.php` key; prefer adding new settings to `gwl.php` and reading them as `config('gwl.your_key')`.

## Testing

- Plain PHPUnit, not Pest. Test suites are `tests/Unit` and `tests/Feature` (see `phpunit.xml`); in practice almost all coverage is under `tests/Feature/{Module}/`.
- Tests run against an **in-memory SQLite DB** (`phpunit.xml` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), with mail/queue/cache/session drivers swapped to array/sync equivalents. `BCRYPT_ROUNDS` is dropped to 4 for speed.
- `RefreshDatabase` is the standard trait on feature tests that touch the DB.
- Livewire components are tested with `Livewire::test(ComponentClass::class)->set(...)->assertSee(...)`, not through raw HTTP requests to their host route.
- Most tests build their own fixture data inline (roles, module_access, regions/districts/departments/job titles, employees) rather than relying on factories — factories only exist for the newer Transport module models and `User`. Follow the existing inline-builder pattern (see `tests/Feature/Staff/EmployeeDeactivationTest.php` for a representative example: `createStaffUser()` / `createEmployee()` helper methods) when adding tests for older modules, and use factories when adding tests for Transport.
- `Notification::fake()` is used liberally to avoid hitting real mail during tests that trigger user creation/invites.

Run tests:
```bash
php artisan test                 # full suite, colorized
php artisan test --filter=Name   # single test/class
composer run test                # same as above, clears config cache first
```

## Running migrations / local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate              # creates database/database.sqlite tables (touch the file first if it doesn't exist)
php artisan db:seed              # runs DatabaseSeeder: roles -> permissions -> module_access -> per-module role/permission seeders -> TransportSeeder -> HolidaySeeder -> SuperAdminSeeder
npm install
npm run build
```

Or the one-shot composer script: `composer run setup`.

Local development (serves app + queue listener + log tail + Vite, all concurrently):
```bash
composer run dev
```

Other useful commands:
```bash
php artisan route:list --except-vendor    # inspect the full route table
php artisan migrate:fresh --seed          # nuke and rebuild the local DB
php artisan gwcl:auto-checkout-visitors   # manually run the visitor auto-checkout job
php artisan schedule:work                 # run the scheduler locally (visitor checkout + transport expiry checks)
```

Seeding order matters: `DatabaseSeeder` runs `RoleSeeder` and `PermissionSeeder` before any module-specific role/permission seeder, and `SuperAdminSeeder` last. Two seeders (`LeavePermissionsSeeder`, `LeaveRolePermissionSeeder`) exist in `database/seeders/` but are **not** called by `DatabaseSeeder` — they're legacy/superseded by the migration-embedded seeding described above; don't assume they run.

## Known rough edges (verified against current code)

- `App\Http\Controllers\Leave\LeaveRequestController` references a `leave.access` middleware alias and a singular `$user->hasRole()` method that don't exist elsewhere in the codebase (`User` only defines `hasRoles()`, plural), and the controller isn't referenced anywhere in `routes/web.php`. Treat it as dead/legacy code, not a pattern to copy.
- Two separate `EnforcesModuleAccess` traits exist (`Http/Controllers/Concerns` and `Livewire/Concerns`) with identical intent — they are not shared, so a fix to module-access logic needs to be applied in both places.
- `UacController` has some commented-out legacy code blocks left in place (e.g. in `store()`) — safe to ignore/clean up but don't be confused by it when reading the file.
- `APP_DOCUMENTATION.md` / `docs/` predate the Assets and Transport modules; don't treat their silence on those modules as "not implemented."
