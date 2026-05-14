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
5. Sync allowed roles.

## Role Governance

- `super_admin` and `employee` roles are hidden from regular role management.
- Admin and super admin can create roles.
- Super admin has widest update/delete authority for role definitions.
- Deletion of custom role requires no assigned users.

## ICT Region Scope

When actor is ICT Team only:

- Users list scoped to actor region.
- Employee search scoped to actor region.
- Cannot modify users outside region.
- Cannot update own roles.

## Key Files

- `app/Http/Controllers/UacController.php`
- `app/Livewire/Uac/RoleAccessManager.php`
- `app/Http/Requests/Uac/StoreUserRequest.php`
- `app/Http/Requests/Uac/UpdateUserRequest.php`
