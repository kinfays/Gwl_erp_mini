# Module: Staff

## Scope

Employee records, staff directory views, and HR master data management.

## Main Features

- Employee listing with scoped visibility
- Employee create/edit form
- Employee activation/deactivation with reason
- Departments manager
- Regions manager
- Locations manager
- Job titles manager
- Staff imports and exports

## Visibility Scoping

Implemented in `App\Services\Staff\EmployeeDirectory`.

Highlights:

- Super admin and head office HR: broad visibility
- Regional HR and regional chief manager: same region
- District manager: same district
- Departmental/chief/manager roles: departmental and location-based scope

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
