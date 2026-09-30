# Architecture and Access Control

## Request Flow

1. User logs in with `staff_id` + password.
2. `EnsureUserIsActive` blocks inactive users and inactive employee profiles.
3. `must_change_password` users are redirected to profile/password update.
4. Route middleware enforces auth, module, role, and permission checks.
5. Livewire components apply module checks again for defense-in-depth.

## Middleware Aliases

Defined in `bootstrap/app.php`:

- `active` => `EnsureUserIsActive`
- `module` => `CheckModuleAccess`
- `role` => `CheckRole`
- `permission` => `CheckPermission`

## Navigation

`App\Support\ErpNavigation` builds:

- Module tabs
- Module-specific sidebar links
- Identity info (name, role tags, location)

## Access Model

Core entities:

- `users`
- `employees`
- `roles`
- `permissions`
- `user_roles`
- `role_permissions`
- `module_access`

Notable rules:

- Super admin bypass for role/permission checks; super admin accounts, roles and audit rows are invisible to everyone else.
- The `admin` role is shown as "Global Admin": Head Office staff only, gets UAC and leave behaviors through app logic.
- Leave module is always included in user accessible modules.
- ICT team without admin/super_admin works in one location scope (Head Office, or one region) and only hands out `ict_assignable` roles.
- All of it is decided in `App\Services\Uac\RoleGrantPolicy` — see `docs/04-module-uac.md`.

## Notifications

Two in-app channels:

- General bell: Laravel `notifications` table (non-letters)
- Letters bell: `letter_notifications` table

Both support sound cue when unread count increases.
