# Routes and Testing

## Routes Snapshot

As of 2026-05-12, `php artisan route:list --except-vendor` reports 69 routes.

Main groups:

- Dashboard: `/dashboard`
- UAC: `/uac/*`
- Staff: `/staff/*`
- Leave: `/leave/*`
- Letters: `/letters/*`
- Visitors: `/visitors/*`
- Public kiosk: `/kiosk`
- Auth/profile flows from Breeze

## Important Route Behaviors

- Most ERP routes require `auth` and `active` middleware.
- Module groups also use `module:<slug>` middleware.
- Sensitive actions add `role:*` and/or `permission:*` checks.

## Existing Feature Test Coverage

Auth:

- default password gate
- reset password view behavior
- employee self-delete restrictions

UAC:

- users pagination
- ICT region-scoped access
- role assignment restrictions

Staff:

- employee list pagination/per-page
- employee form district/region behavior
- deactivation reason and login blocking
- employee-user sync behavior
- master data manager guardrails
- import error clear flow

Visitors:

- history date-range filtering
- export date-range correctness

## Run Tests

```bash
php artisan test
```
