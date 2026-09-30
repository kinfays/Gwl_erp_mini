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

## Deactivation Behavior

- Deactivation requires reason (`left`, `retired`, `dead`).
- Reactivation clears reason.
- Login is blocked for deactivated users/employees.

## Key Files

- `app/Http/Controllers/Staff/StaffController.php`
- `app/Livewire/Staff/AllEmployees.php`
- `app/Livewire/Staff/EmployeeForm.php`
- `app/Observers/EmployeeObserver.php`
- `app/Services/Staff/EmployeeDirectory.php`
