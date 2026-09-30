# Module: UAC

## Scope

User and access management for the ERP.

## Main Features

- User dashboard stats
- User list with search/filter/pagination
- Create user from existing employee
- Role assignment and updates
- Activate/deactivate user
- Resend invite for first login
- Roles/permissions matrix editor
- Audit log viewer
- Import workspace for users and master data

## User Provisioning Flow

1. Select employee record.
2. Create user with default password (`12345`).
3. Set `must_change_password = true` where column exists.
4. Send invite email with password reset link.
5. Assign the chosen roles (all checked against the new account, in the same transaction — a refused role cancels the creation).

## Who can do what

All of it is decided in one place, `App\Services\Uac\RoleGrantPolicy` (decisions) and `RoleAssignmentService`
(applies and audits). Controllers, the roles screen, the users import and the transfer rule ask them; don't test
role names yourself.

Tiers, highest first: **super_admin > Global Admin (slug `admin`) > ICT team > everyone else**. Nobody grants a
role above their own tier.

| | super_admin | Global Admin | ICT team | others |
|---|---|---|---|---|
| Sees super_admin accounts, role and audit rows | yes | no | no | no |
| Assign any role (except super_admin) to anyone, anywhere | yes (incl. super_admin) | yes | no | no |
| Assign / remove roles flagged `ict_assignable` | yes | yes | only inside own location scope | no |
| Create roles, edit role permissions | yes | yes (not protected roles) | no | no |
| Edit protected roles (`admin`) | yes | no | no | no |
| Delete a custom role (zero users) | yes | no | no | no |
| Import users (bulk) | yes | yes | no | no |
| Change their own roles | yes | yes | never | – |

- **super_admin** is a developer back-end account. It is left out of user lists, role lists, employee search, staff
  profiles, counts and exports for everyone else (`visibleTo($viewer)` scopes on `User`, `Employee`, `Role`). Its audit
  rows are hidden too, except role/access changes, which show with the actor as "System" (`AuditLog::visibleTo()`,
  `AuditLog::ROLE_ACCESS_ACTIONS`; the flag `audit_logs.actor_is_super_admin` is frozen when the row is written, so it
  survives the account being demoted or deleted). Operational pickers (visitor hosts, letter recipients…) keep using
  `visibleInErp()`: a developer account is never a real member of staff there.
- **Global Admin** is `admin` shown under a new display name. It, **Head Office HR** (`hr_headoffice`) and **Chief
  Manager** (`chief_manager`) are the Head Office-only roles (`RoleGrantPolicy::HEAD_OFFICE_ROLES`): assignment to
  anyone who isn't Head Office staff is refused (for every actor), and when someone holding any of them is moved out of
  Head Office — through the staff form, an import, renaming their district on the locations screen, or any other save of
  the employee — `EmployeeObserver` removes those roles in the same transaction, audits it
  (`head_office_roles_removed_on_transfer`, with the roles removed, the actor and old/new location) and notifies the person,
  whoever moved them and the Global Admins. They can't be given back until the person is at Head Office again. Their other
  roles are left alone.
- **Assets:** the Head Office ICT team can *list and filter* every region's assets (inventory, phones, network,
  dashboard, maintenance, issue and agent reports); a regional ICT user sees only their own region. Creating and
  editing stay inside the ICT user's own region (rows elsewhere show "Read only"), and MDM is unchanged: it stays
  region-scoped for every ICT user.
- **ICT team** works in one **location scope**: Head Office, or one region (`User::ictScope()`; `isRegionScopedIct()`
  survives as a thin wrapper of `isScopedIct()`). Head Office is a district, and its staff share the Head Office
  district's `region_id`, so a regional ICT scope also excludes `location_type = HeadOffice`. They can only assign or
  remove roles with `roles.ict_assignable = true`, only to users in their scope, never their own.
- A role must fit the person's location (`RoleGrantPolicy::HEAD_OFFICE_ROLES` / `REGIONAL_ROLES`): `admin`,
  `hr_headoffice`, `chief_manager` only to Head Office staff; `hr_region`, `regional_chief_manager`, `district_manager`
  only to regional or district staff. Refused for every actor, with a message.
- **Anti-escalation:** nobody grants super_admin (unless they are one) or a role above their tier, and nobody grants a
  role — or adds a permission to one — that carries governance permissions (`uac.*`) they don't hold themselves.
  Operational permissions (staff, leave, letters…) are deliberately not compared: the ICT team hands those roles out
  by design. See `RoleGrantPolicy::GOVERNED_MODULES`.
- Editing a user **adds and removes only the roles the actor may manage**; every other role the person holds (a Global
  Admin role an ICT user can't touch, the implicit `employee` role) stays exactly as it was.

### Role classification (`roles` table)

| Column | Meaning | Default |
|---|---|---|
| `ict_assignable` | The ICT team may assign/remove it (within its scope). Toggle on the roles screen (Global Admin / super_admin). Never settable on `admin`, `ict_team` or `super_admin`. | false |
| `is_protected` | The role's definition can only be changed by a super_admin and it can't be deleted. | false |

Seeded (migration `2026_09_30_000001` and `RoleSeeder`):

- `ict_assignable = true`: `employee` (implicit, never listed), `manager`, `departmental_manager`, `district_manager`,
  `chief_manager`, `regional_chief_manager`, `hr_region`, `hr_headoffice`, `secretary`, `receptionist`,
  `transport_manager`, `driver`.
- `ict_assignable = false`: `admin`, `super_admin`, `managing_director`, `ict_team`, `credit_union_officer`,
  `credit_union_committee`, and every custom role until flagged.
- `is_protected = true`: `super_admin`, `admin`.

The `managing_director` role (Leave approval) is Global-Admin-only and has no location rule; the Leave routing roles
line up with the location rules above: `chief_manager` at Head Office, `regional_chief_manager` and `district_manager`
in a region/district, `departmental_manager` at either.

## Audit

Every role assignment/removal (`role_assigned`, `role_removed`, with the whole role set before and after, the actor and the
target's location scope), role create/edit/delete, permission change and Global Admin removal is written with old/new values.
Assigning or removing `super_admin` is logged as `super_admin_role_assigned` / `super_admin_role_removed` and never shown to
anyone else. The audit log page itself is still a super_admin page.

## Key Files

- `app/Services/Uac/RoleGrantPolicy.php`, `app/Services/Uac/RoleAssignmentService.php`
- `app/Http/Controllers/UacController.php`
- `app/Livewire/Uac/RoleAccessManager.php`
- `app/Observers/EmployeeObserver.php` (Global Admin transfer rule)
- `app/Http/Requests/Uac/StoreUserRequest.php`
- `app/Http/Requests/Uac/UpdateUserRequest.php`
