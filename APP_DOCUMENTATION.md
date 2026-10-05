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
- Chart.js 4.5 (npm, bundled as `resources/js/charts.js` and loaded only by chart components via Livewire `@assets`)
- signature_pad 5.1 (npm, bundled as `resources/js/signature-pad.js` and loaded only by the visitor kiosk)
- Node.js 22+ for the build tooling

### Infrastructure and Runtime Notes

- Scheduler commands configured for visitor auto-checkout and annual leave carry-over forfeiture
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
- `super_admin` is a developer account: its user, role, employee and staff records, counts and audit rows are hidden from everyone but a super admin (its role/access changes still show to others, with the actor as "System").
- The `admin` role (slug unchanged) is displayed as **Global Admin**. It must be a Head Office staff member. Global Admin, Head Office HR and Chief Manager are Head Office-only roles: assigning them to anyone else is refused, and they are removed automatically (audited, with a notification) when the holder is moved out of Head Office — including by renaming their district.
- ICT team users work in one location scope — Head Office, or one region — and can only assign or remove roles flagged `ict_assignable`, only to users in that scope. In Assets the Head Office ICT team can list every region's assets (edits stay in their own region). A role must also fit the person's location (e.g. `hr_headoffice` only for Head Office staff).
- Nobody grants a role above their own tier (super admin > Global Admin > ICT > others), super admin, or a role carrying governance permissions they don't hold. All of this is decided in `App\Services\Uac\RoleGrantPolicy` (`docs/04-module-uac.md`).
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

Annual leave carry-over that is still unused after its expiry date is forfeited daily at 00:30:

```bash
php artisan leave:forfeit-expired-carry-over            # add --dry-run to preview without saving
```

Each employee's Annual entitlement for the year is generated daily at 00:20 (on 1 January it creates the new year; other days it picks up joiners and newly graded staff). It is idempotent and can be run by hand for any year:

```bash
php artisan leave:generate-entitlements                 # the current year
php artisan leave:generate-entitlements 2027
```

Scheduler entries are defined in `routes/console.php`.

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
- `leave_entitlements`: each employee's Annual entitlement per year (grade snapshot, tenure, gross, compulsory and net days)
- `compulsory_leave_periods`: the year's compulsory leave (days, start and resume dates)
- `compulsory_leave_deductions`: the earlier category-based batch deductions (read-only history; those years are never deducted again)
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

1. Global Admin or ICT (within their scope) selects an employee.
2. User is created with default password (`12345`) and `must_change_password=true`.
3. Roles are assigned, each checked against the actor and the employee's location; a refused role cancels the creation.
4. Invite email with password-reset token is sent.

### Role management rules

- The implicit `employee` role is never listed; `super_admin` is listed only to a super admin and is locked for everyone.
- Global Admin and super admin can create roles and edit role permissions (roles are shared definitions). The protected roles (`super_admin`, `admin`) can only be changed by a super admin.
- Roles start Global-Admin-only; a Global Admin flags a role `ict_assignable` to let ICT hand it out.
- Only `super_admin` can delete custom roles, and only if the role has no users.
- Editing a user only adds/removes the roles the actor may manage; other roles they hold are left alone.

### ICT location scoping

If actor is ICT Team without Global Admin/super_admin, they work in one scope (`User::ictScope()`): Head Office, or one region (its districts, excluding Head Office staff who share the region id).

- User visibility and employee search are constrained to that scope.
- Can only assign/remove roles flagged `ict_assignable`, only to users in that scope.
- Cannot assign admin (Global Admin), ict_team, managing_director or credit union roles.
- Cannot update own roles, edit role permissions, create or delete roles.

### Audit log visibility

Audit rows written by a super admin are visible only to a super admin (`AuditLog::visibleTo()`), except role/access changes, which others see with the actor shown as "System".

## 6.2 Staff Management

### Main capabilities

- Employee listing with role-based visibility
- Employee create/edit form
- Employee activation/deactivation with a deactivation reason (Retirement, Resignation, Contract ended, Transfer, Other) and date
- Staff **Grade** (Junior Gd. Level 1-6, Charwoman, Snr. Gd. Level 1-4, Mgt. Gd. Level 1-4), which fixes the category; shown on the list (with a grade filter), the profile and the export
- Master data management: departments, regions, locations, job titles
- Staff exports
- Staff bulk import workspace (optional Grade column; rows without a grade import and are listed as "grade missing")
- **Staff Reports** (formerly "Staff Leave Reports"): leave and headcount charts, a breakdown by category and grade, and an Excel export

### Employee visibility scope (EmployeeDirectory)

- `super_admin`, `hr_headoffice`: all visible employees
- `hr_region`: same region
- `regional_chief_manager`: same region
- `district_manager`: same district
- `chief_manager`, `departmental_manager`: scoped by department + location context
- `manager`: scoped by department + unit + location context
- Any other role, or any scoped role whose account has no linked employee record: no employees

The same scope is enforced on edit, deactivate/reactivate and bulk import, not just the list. `hr_region` can also only place employees in districts of their own region (create, edit and import); moving staff to another region is done by head office HR.

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
- Grade-based annual entitlements and compulsory leave (Compulsory Leave page)
- HR analytics dashboard
- Leave exports/reports

### Leave statuses and lifecycle

- `Planned`
- `Pending Approval`
- `Approved`
- `Denied`

Typical flow:

1. Employee saves planned or submits request. Approvers are resolved (and must exist) on submission.
2. Manager recommendation stage (`Pending` -> `Recommended` or `Rejected`) — skipped for managers, who apply
   directly to the level above (single-stage: `Pending Approval` -> `Approved`/`Denied`).
3. Final decision (`Approved` or `Denied`) by the chief / regional chief / Managing Director.
4. Denied requests can be reopened to `Planned` by requester.
5. After final approval, HR of the applicant's scope (region, or Head Office) is emailed and notified in-app.

### Approval chain resolution

`LeaveApprovalChainResolver` selects the recommender and final approver from the applicant's `location_type`
and role (full table and rules: `docs/06-module-leave.md`):

- `District`: `district_manager` (same district) -> `regional_chief_manager` (same region)
- `Region`: `departmental_manager` (same dept + region) -> `regional_chief_manager`
- `HeadOffice`: unit `manager` (same dept + unit) or, with no unit, `departmental_manager` -> `chief_manager` (same department)
- `district_manager` / `departmental_manager` (region/district) apply directly to the `regional_chief_manager`;
  a Head Office `manager` / `departmental_manager` applies directly to their department's `chief_manager`
- `chief_manager` and `regional_chief_manager` apply directly to the `managing_director`

Only active users/employees are resolved; when several hold a role all are notified and the first to act wins.
The applicant is never their own approver; a missing role blocks submission with a message naming it.

### Special leave rules

- Casual leave submission is blocked while annual remaining > 0.
- Working days exclude weekends and observed holidays.
- Annual carry-over (last year's unused Annual days) can be used until 1 Jan + `GWL_CARRY_OVER_EXPIRY_DAYS` (default 90, so 1 April). Annual leave dated before that uses carry-over first; carry-over still unused on the expiry date is forfeited by the `leave:forfeit-expired-carry-over` job (`leave_balances.carry_over_forfeited_days`, audit-logged).
- Sick leave is treated as effectively unlimited (`9999` virtual entitlement).

### Annual entitlement by grade

Gross days come from the employee's grade (`employees.grade`) and years of service at 1 January of the leave year: Junior Gd. Level 1-3 get 26 days under 10 years' service and 31 from 10 years; Junior Gd. Level 4-6 get 31; Snr. Gd. and Mgt. Gd. (every level) get 36; Charwoman (Contract) is not eligible and cannot apply for leave. Staff with no grade yet keep the flat 31 days and no compulsory deduction until they are graded. The numbers are in `config/gwl.php` (`leave_annual_days`). Entitlements are stored per employee and year in `leave_entitlements`, generated by `leave:generate-entitlements` (idempotent) and recalculated, with an audit row, when a grade, hire date, location or the year's compulsory days change; days already taken are never taken back. Full detail: `docs/06-module-leave.md`.

### Compulsory leave

- A number of days (default 11) per year, set on **Staff Management -> HR Tools -> Compulsory Leave** by Head Office HR, Global Admin or super_admin (permission `leave.manage_compulsory`; regional HR and everyone else are refused).
- Taken from the **gross** entitlement of Head Office and regional office staff (Senior 36 - 11 = 25); district and contract staff are exempt. It is not a leave request and is never charged to `used_days`, so approving leave never deducts it again.
- The leave home Annual card shows Gross entitlement / Compulsory leave / Available. With no record for the year the default applies and the page warns. Saving is audited and recalculates the year's entitlements.
- Years deducted the old way (the earlier category-based screen) are left alone.

### Approval letters

Every finally-approved request gets a printable A4 letter in the company template's layout (letterhead, addressee, approval paragraphs, signing space, Board of Directors footer), with a frozen snapshot, optional approver signature (drawn or uploaded, stored encrypted on a private disk, applied only by its owner) and acting-approver support. Letter Settings and Acting Assignments are under Staff Management -> HR Tools; My Signature is in the Leave sidebar. See `docs/13-leave-letters.md`.

### HR analytics

`GET /leave/hr-analytics` (Head Office HR, Global Admin, super_admin: every region; regional HR: their own region): birthdays, anniversaries, 5/10/15/20-year milestones, approaching retirement (age `GWL_RETIREMENT_AGE`, default 60), headcount, hires, exits and turnover (transfers excluded), distribution, exit reasons, staff missing a grade and the entitlement summary. See `docs/12-hr-analytics.md`.

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

The employees template has an optional `grade` column after `category`: any case or spelling of a grade is accepted, an unknown grade rejects its row, and a category may be left blank when a grade is given.

## 7.3 Import pipeline

1. Download template
2. Upload file for preview
3. Validate rows and compute failure percentage
4. Block run if failure percentage exceeds configured max
5. Run transactional upserts on valid rows

## 7.4 Export capabilities

- Staff employees export (`xlsx`, with a Grade column)
- Staff Reports export (`xlsx`: summary, by grade, by district, on leave now, staff list)
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
- `GET /leave/team-dashboard`, `/leave/reports`, `/leave/compulsory`, `/leave/hr-analytics`
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
