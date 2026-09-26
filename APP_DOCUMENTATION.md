# GWL ERP Project Documentation

Last updated: 2026-09-26

## 1. Project Overview

This repository is a Laravel-based ERP portal focused on internal operations for:

- Leave management
- Staff directory and HR data
- User access control (UAC)
- Letters and document routing
- Visitor check-in and checkout

The application uses role-based access control, module-level access gating, Livewire-driven UI screens, and audit logging for sensitive actions.

## 2. Technology Stack

Versions as pinned in `composer.lock` / `package-lock.json` (dependency update of 2026-09-26).

### Backend

- PHP 8.4.1+ (production runs PHP 8.5)
- Laravel 13.33 (Symfony 8.1 components)
- Laravel Breeze 2.4 (authentication scaffolding)
- Livewire 4.4
- Maatwebsite Excel 4.0 with PhpSpreadsheet 5 (imports/exports)
- dompdf/dompdf 3.1 (PDF export)
- PHPUnit 13 (tests)

### Frontend

- Blade templates
- Livewire components
- Alpine.js 3
- Tailwind CSS 4 (CSS-first configuration in `resources/css/app.css`; no `tailwind.config.js`)
- Vite 8 with `laravel-vite-plugin` 3
- Node.js 22+ for the build tooling

### Infrastructure and Runtime Notes

- Scheduler command configured for visitor auto-checkout
- Queue listener included in local dev script
- `URL::forceScheme('https')` is enabled in `AppServiceProvider`

## 3. High-Level Architecture

### 3.1 Request and Access Flow

1. User logs in using `staff_id` + password.
2. `EnsureUserIsActive` middleware blocks inactive users/employees.
3. If `must_change_password` is true, the user is forced to profile/password update.
4. Route middleware applies:
   - `module:<slug>` for module access
   - `role:<roles>` for role checks
   - `permission:<permission>` for granular actions
5. Livewire components also enforce module access (defense-in-depth).

### 3.2 Navigation Model

`App\Support\ErpNavigation` builds:

- Top module tabs
- Module-specific sidebar menu
- Identity context (name, role labels, location)

Modules are visible based on role + module_access records, except `leave`, which is always included by user model logic.

### 3.3 Core Security Rules

- Super admin bypass exists for role/permission checks.
- Employee and super admin records are hidden in several UAC/staff contexts.
- ICT team users are region-scoped in UAC unless they also have admin/super_admin.
- Employee self-deletion is blocked.

## 4. Installation and Local Development

## 4.1 Prerequisites

- PHP 8.4.1+ (8.5 recommended, matching production)
- Composer
- Node.js 22+ and npm
- Database (SQLite is default in `.env.example`)

## 4.2 Quick Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
npm install
npm run build
```

Or use the built-in Composer setup script:

```bash
composer run setup
```

## 4.3 Run in Development

```bash
composer run dev
```

This starts:

- `php artisan serve`
- `php artisan queue:listen --tries=1 --timeout=0`
- `php artisan pail`
- `npm run dev`

## 4.4 Scheduler

Visitor auto-checkout is scheduled daily at configured close time.

Command:

```bash
php artisan gwcl:auto-checkout-visitors
```

Scheduler entry is defined in `routes/console.php`.

Run scheduler locally with:

```bash
php artisan schedule:work
```

## 4.5 Custom Environment Settings

Defined via `config/gwl.php` and `config/gwcl.php`:

- `GWL_AUTO_CHECKOUT_TIME` (default `18:00`)
- `GWCL_VISITORS_AUTO_CHECKOUT_TIME` (fallback alias)
- `GWL_CARRY_OVER_EXPIRY_DAYS` (default `90`)
- `GWL_LEAVE_NOTIFICATION_POLL_SECONDS` (default `90`)
- `GWL_VISITOR_KIOSK_RESET_SECONDS` (default `5`)
- `GWL_MAX_IMPORT_FAILURE_PERCENT` (default `20`)

## 5. Core Data Model

## 5.1 Identity and Access

- `users`: login identity, staff_id, password, active state, `must_change_password`, last login
- `employees`: HR profile tied by `employee_id` and/or `staff_id`
- `roles`: system and custom roles
- `permissions`: module-scoped action slugs
- `user_roles`: user-role pivot
- `role_permissions`: role-permission pivot
- `module_access`: module toggle per role
- `audit_logs`: action history with old/new values and metadata

## 5.2 Leave

- `leave_requests`: status-driven workflow records
- `leave_balances`: per employee/type/year balances
- `compulsory_leave_deductions`: batch annual leave deductions
- `holidays`: holiday calendar used in working-day calculations

## 5.3 Letters

- `mail_letters`: primary letter record
- `letter_status_logs`: status timeline per secretariat
- `routing_histories`: dispatch and hardcopy-receipt chain
- `letter_remarks`: manager/chief/secretary remarks
- `letter_notifications`: letters-specific notification inbox

## 5.4 Visitors

- `visitors`: kiosk entries, signatures, checkout tracking

## 5.5 Notification Infrastructure

- `notifications`: Laravel database notifications (general bell)

## 6. Modules and Functional Behavior

## 6.1 UAC (User Access Control)

### Main capabilities

- UAC dashboard stats (users/roles/permissions/audit logs)
- User list with search, role filter, status filter, pagination
- Create user from existing employee
- Assign and update roles
- Activate/deactivate users
- Resend invite links for first-time setup
- Roles and permissions editor (Livewire)
- Audit log page
- Bulk import workspace (users + master/staff data)

### User creation flow

1. Admin selects employee.
2. User is created with default password (`12345`) and `must_change_password=true`.
3. Invite email with password-reset token is sent.
4. Roles are synced (filtered by actor authorization).

### Role management rules

- `super_admin` and `employee` roles are excluded from editable UI.
- `admin` and `super_admin` can create roles.
- Only `super_admin` can generally edit role access mappings.
- `ict_team` role can be edited by `admin` or `super_admin`.
- Only `super_admin` can delete custom roles, and only if role has no users.

### ICT region scoping

If actor is ICT Team without admin/super_admin:

- User visibility is constrained to same region.
- Employee search for user creation is constrained to same region.
- Cannot assign admin/ict_team roles unless authorized.
- Cannot update own roles.

## 6.2 Staff Management

### Main capabilities

- Employee listing with role-based visibility
- Employee create/edit form
- Employee activation/deactivation with deactivation reason
- Master data management: departments, regions, locations, job titles
- Staff exports
- Staff bulk import workspace

### Employee visibility scope (EmployeeDirectory)

- `super_admin`, `hr_headoffice`: all visible employees
- `hr_region`: same region
- `regional_chief_manager`: same region
- `district_manager`: same district
- `chief_manager`, `departmental_manager`: scoped by department + location context
- `manager`: scoped by department + unit + location context

### Employee to user synchronization

`EmployeeObserver` auto-syncs user accounts:

- On employee create/update, user is created/repaired if missing.
- User receives default password and invite notification when created.
- User active state follows employee active state.

### Deactivation behavior

- Required reason on deactivation: `left`, `retired`, `dead`
- Reactivation clears reason.
- Employee user account is deactivated/reactivated with employee state.

## 6.3 Leave Management

### Main capabilities

- Employee self-service apply form
- Planned drafts and submission workflow
- Approvals queue for manager/chief
- Read-only approvals visibility for HR
- My history with edit/delete/reopen rules
- HR and manager dashboards
- Compulsory leave deduction engine
- Leave exports/reports

### Leave statuses and lifecycle

- `Planned`
- `Pending Approval`
- `Approved`
- `Denied`

Typical flow:

1. Employee saves planned or submits request.
2. Manager recommendation stage (`Pending` -> `Recommended` or `Rejected`).
3. Final chief decision (`Approved` or `Denied`).
4. Denied requests can be reopened to `Planned` by requester.

### Approval chain resolution

`LeaveApprovalChainResolver` selects manager + chief based on `location_type`:

- `HeadOffice`:
  - chief: `chief_manager` in same department
  - manager: `manager` by same dept+unit if unit exists, else `departmental_manager`
- `Region`:
  - manager: `departmental_manager` (same dept + region)
  - chief: `regional_chief_manager` (same region)
- `District`:
  - manager: `district_manager` (same district)
  - chief: `regional_chief_manager` (same region)

### Special leave rules

- Casual leave submission is blocked while annual remaining > 0.
- Working days exclude weekends and observed holidays.
- Annual carry-over is available until configurable expiry window.
- Sick leave is treated as effectively unlimited (`9999` virtual entitlement).

### Compulsory deductions

- Applies annual leave deductions to selected categories.
- Date window constrained to Dec 1 - Jan 31 boundary logic in component.
- Can exclude one `location_type`.
- Requires explicit override confirmation if same-year deduction exists.

## 6.4 Letters and Document Routing

### Main capabilities

- Create internal/external letters
- Auto-generate serial number (`<REGION_INITIALS>-<YEAR>-<NNN>`)
- Active/closed listing with filters
- Dispatch to secretaries
- Hardcopy receipt confirmation before onward dispatch
- Add/update remarks (manager/chief + secretary text)
- Close/reopen (creator-only)

### Workflow behavior

- New letter creates initial status log (`Received`) for creator.
- Dispatch creates:
  - routing history record (pending `received_confirm=false`)
  - status log for recipient (`Received`)
  - letter notification for recipient
- Recipient must confirm hardcopy receipt before dispatching onward.

### Authorization highlights

- Create: `letters.create` or super_admin
- Forward/dispatch: `letters.forward` or super_admin
- Remark: `letters.remark` or super_admin
- Edit/close/reopen: creator ownership checks

## 6.5 Visitors

### Main capabilities

- Public kiosk (`/kiosk`) for step-by-step check-in
- Duplicate in-day warning by phone for active visitors
- 1-3 digit checkout code generation
- Self-checkout with code + signature
- Receptionist today log and historical log
- Excel and PDF export by date range

### Kiosk steps

1. Name and phone
2. Employee selection (host)
3. Purpose
4. Signature and submit

### Checkout modes

- Self checkout (`checked_out_by = self`)
- Receptionist checkout (`checked_out_by = receptionist`)
- Scheduled auto checkout (`checked_out_by = auto`)

## 7. Import and Export System

## 7.1 Import contexts

- UAC context: includes `users` plus staff/master data
- Staff context: excludes `users`

## 7.2 Supported import types

- `employees`
- `departments`
- `regions`
- `districts`
- `job_titles`
- `users` (UAC only)

## 7.3 Import pipeline

1. Download template
2. Upload file for preview
3. Validate rows and compute failure percentage
4. Block run if failure percentage exceeds configured max
5. Run transactional upserts on valid rows

## 7.4 Export capabilities

- Staff employees export (`xlsx`)
- Approved leaves export (`xlsx`, plus report format support in Livewire)
- Manager team leave export (`xlsx`)
- Visitor exports (`xlsx` and PDF)

## 8. Notification and Messaging

## 8.1 Channels

- Mail notifications:
  - Invite user notification
  - Leave submitted/recommended/approved/denied emails
- Database notifications:
  - General ERP notifications (non-letters bell)
  - Letters-specific notification stream

## 8.2 UI behavior

- Global toast component listens to `toast` browser events
- Notification sound is triggered when unread count increases
- Separate bell components are used for general vs letters notification scopes

## 9. Routes by Module (Key Entry Points)

Total routes (non-vendor): 69

### Authentication/Profile

- `GET /login`, `POST /login`, `POST /logout`
- `GET /forgot-password`, `POST /forgot-password`
- `GET /reset-password/{token}`, `POST /reset-password`
- `GET/PATCH/DELETE /profile`

### Dashboard

- `GET /dashboard`

### UAC

- `GET /uac`
- `GET /uac/users` and user CRUD/toggle/invite
- `GET /uac/roles`
- `GET /uac/audit-log`
- `GET/POST /uac/import/*`

### Staff

- `GET /staff`
- `GET /staff/create`, `GET /staff/{employee}/edit`
- `PATCH /staff/{employee}/status`
- `GET /staff/departments|regions|locations|job-titles`
- `GET /staff/export`
- `GET/POST /staff/import/*`

### Leave

- `GET /leave`
- `GET /leave/apply`, `/leave/my-history`, `/leave/requests`, `/leave/approvals`
- `GET /leave/team-dashboard`, `/leave/reports`, `/leave/compulsory`
- Leave export endpoints under `/leave/export/*`

### Letters

- `GET /letters`
- `GET /letters/active`
- `GET /letters/new`
- `GET /letters/closed`

### Visitors

- Public kiosk: `GET /kiosk`
- Module: `GET /visitors`, `GET /visitors/history`
- Exports: `GET /visitors/export/excel`, `GET /visitors/export/pdf`

## 10. Testing Coverage Summary

Feature tests currently cover:

- Auth and password-gate behavior (`must_change_password`)
- Profile restrictions for employee accounts
- UAC pagination and ICT region-scoped access controls
- Staff pagination, employee form behavior, employee deactivation behavior
- Employee-user sync observer behavior
- Staff data managers (regions/job titles) guardrails
- Import validation and error clearing workflows
- Visitors history filtering and export date-range accuracy

Run tests:

```bash
php artisan test
```

## 11. Operational Notes and Caveats

- `URL::forceScheme('https')` is always enabled. Ensure reverse proxy/SSL setup matches environment.
- `EmployeeObserver` can create user accounts automatically when employee records are created/updated.
- Leave module appears in accessible modules by design (`User::getAccessibleModules` forces inclusion).
- Some legacy seeders exist (`LeavePermissionsSeeder`, `LeaveRolePermissionSeeder`) but are not invoked by `DatabaseSeeder`.
- Notifications table is required for in-app notification bells.

## 12. Suggested Next Documentation Additions

- API contract docs if JSON endpoints grow beyond current modal/drawer payloads.
- Sequence diagrams for leave and letter workflows.
- Deployment-specific runbooks (queue workers, scheduler, mail transport, backups).
- Data retention policy for audit logs, notifications, and visitor signatures.
