# Module: Staff

## Scope

Employee records, staff directory views, and HR master data management.

## Main Features

- Employee listing with scoped visibility
- Employee create/edit form
- Employee activation/deactivation with reason
- Departments manager
- Regions manager (also owns each region's **letter prefix**, the start of its Letters serial numbers, e.g. `GA-2026-001`:
  2-6 capital letters or digits, unique. Leave it blank on create and it is derived from the name; changing it later only
  affects future letters, numbers already issued are never rewritten. Saved through the same `create_region` /
  `update_region` audit rows. A prefix that was derived or backfilled may be shorter or longer than that rule allows,
  which does not block renaming the region: the rule applies to a prefix you type.)
- Locations manager (editing a district re-syncs its employees: their `location_type` follows the district's name and their `region_id` its region, and Head Office-only roles are removed from anyone who ends up outside Head Office)
- Job titles manager
- Staff imports and exports
- **Staff Reports** (renamed from "Staff Leave Reports"; route `staff.reports` and permission `staff.view_reports` are unchanged): the leave and headcount charts, a breakdown by category and grade, and an Excel export

## Visibility Scoping

Implemented in `App\Services\Staff\EmployeeDirectory`.

Highlights:

- Super admin and head office HR: broad visibility
- Regional HR and regional chief manager: same region
- District manager: same district
- Departmental/chief/manager roles: departmental and location-based scope
- Scoped roles whose account has no linked employee record: no one (fails closed)

The same scope guards edit, deactivate/reactivate and bulk import. Regional HR can only place employees in districts of their own region.

## Employee/User Sync

`EmployeeObserver` auto-syncs user records from employee lifecycle events:

- Creates missing users
- Keeps staff_id/email aligned
- Mirrors active state
- Sends invite on auto-created user

## Navigation: HR dashboard and HR tools

Staff Management is the first module (Leave is second) and owns the **HR Dashboard** and the **HR Tools** section: HR Analytics, Compulsory Leave, HR Contacts, Leave Reports and Staff Reports. Only the navigation and page chrome moved: the URLs, route names (`leave.hr-dashboard`, `leave.hr-analytics`, `leave.compulsory`, `leave.hr-contacts`, `leave.reports`) and permissions are still the leave module's. Leave Home is everyone's personal leave page (an HR account with no employee record is sent to the HR dashboard). Global Admin has no Staff module access, but sees the Staff tile (landing on the HR dashboard) and the HR items, not the staff list.

## Grade

`employees.grade` holds one of the `App\Enums\StaffGrade` values; that enum is the only place the allowed grades live.

| Grade | Category it fixes |
|---|---|
| Junior Gd. Level 1-6 | Junior Staff |
| Charwoman | Contract (no leave) |
| Snr. Gd. Level 1-4 | Senior Staff |
| Mgt. Gd. Level 1-4 | Management |

- The **category is derived from the grade** (`Employee::saving`), never stored as something else, and the form shows it instead of asking for it. Staff recorded before grades keep their category (including the old "Senior Management") until they are graded.
- The Grade dropdown is **required for new staff**; an existing record without a grade can still be edited until HR grades it. A grade can't be cleared once set.
- Every grade change is audited (`employee_grade_changed`, old and new grade and category) from whichever screen made it, and it recalculates the employee's annual entitlement (see `docs/06-module-leave.md`).
- The grade is on the staff list (with a **Grade** filter, including "No grade set", and a Category filter where "Management" includes the old "Senior Management"), the profile drawer and the employee export. The list's filters live in the URL, so a dashboard card can link straight to a filtered list (`staff.index?grade=none&status=active`).

## Import: the Grade column

The employee template has an optional `grade` column after `category`. Any case or spelling is read as the real grade ("snr gd level 2", "Junior Grade L3"); a grade that isn't one rejects its row with the value named. The category cell may be blank when there is a grade; a category that contradicts the grade is a row error; a row with neither is a row error. **Rows without a grade still import** and are listed as "grade missing" in the preview and counted in the completion message. A file with no `grade` column at all still imports. Re-importing a row without a grade never clears a grade the employee already has. The old "Charwoman" category is stored as Contract.

## Deactivation Behavior

- Deactivation requires a reason: Retirement (`retired`), Resignation (`resigned`), Contract ended (`contract_ended`), Transfer (`transfer`), Other (`other`); the older `left` and `dead` stay valid for existing records.
- Deactivation stamps `deactivated_at` (today, unless a date was given); reactivation clears it and the reason. HR analytics reads exits from it.
- Login is blocked for deactivated users/employees.

## Staff Reports

`App\Livewire\Staff\StaffReports` / `App\Services\Staff\StaffReportService` (permission `staff.view_reports`; scope: Head Office HR and super_admin see every region, regional HR their own; staff holding the super_admin role are not counted for anyone but a super_admin viewer, as in the staff list). Besides the leave charts it shows the active staff **by category** (the four categories, with the pre-grade categories grouped under the one they became), **Senior and Management by level 1-4, Junior by level 1-6**, and a **No grade set** row; each count links to the filtered staff list. **Export Excel** (`staff.reports.export`) writes Summary, By Grade, By District (with the staff by category and the number with no grade), On Leave Now and Staff sheets (grade columns included) from the same payload the page shows.

## Key Files

- `app/Http/Controllers/Staff/StaffController.php`
- `app/Livewire/Staff/AllEmployees.php`
- `app/Livewire/Staff/EmployeeForm.php`
- `app/Observers/EmployeeObserver.php`
- `app/Services/Staff/EmployeeDirectory.php`
