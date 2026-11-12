# Discrepancies between the existing docs and the code (for the development team)

Found while writing the end-user manuals. The code was treated as the source of truth. Roles/permissions below come from running all migrations and seeders on a scratch database; production roles may differ if edited in Access Control.

## Access and navigation

1. **Global Admin (`admin`) module access.** `docs/03` and `docs/04` say Global Admin reaches UAC and Assets (MDM). In the default data it also has `health_safety` and `blog`, and `ErpNavigation::userCanAccessModule()` gives it the Staff tab for the HR tools. The Staff routes themselves exclude it.
2. **`/register`.** `routes/auth.php` still exposes Breeze registration. The login page does not link to it, and `RegisteredUserController` writes `name` and `email` while the users table has `full_name`. Probably dead or broken; consider removing.
3. **Home page.** `DashboardController` passes a hard-coded `'unreadNotifications' => 3`. The home page does not appear to show it, but it should go.
4. **Default password.** `User::DEFAULT_PASSWORD` is a fixed value documented in `docs/04-module-uac.md`. Not reproduced in the manuals; consider replacing with per-user random passwords.
5. **`ict_assignable` roles.** `docs/04` lists the assignable roles; in the seeded data `g_water`, `finance_officer`, `finance_manager`, `audit_officer` and `audit_manager` are also `ict_assignable = 1` and `is_system = 0`. Confirm that is intended (CLAUDE.md says they are ICT-assignable for G-Water; Finance/Audit not stated).

## Leave

6. **Carry-over expiry.** `docs/06` says "default 1 April"; `config/gwl.php` sets `carry_over_expiry_days` to 90 (days into the year). The manual says "about 90 days (around 1 April)".
7. **`leave.export`.** Seeded to `admin` only (Super Admin bypasses). Head Office HR and Regional HR cannot open **Leave Reports** or the team export unless granted. `docs/06` implies HR reports. The manual states the default.
8. **No `leave.apply` for ordinary employees.** Apply/approve permissions are not tied to routes (the approval chain decides), but the permission rows are only given to `admin` and `managing_director`. Confirm no screen needs them.

## Letters

9. **Who can hold letters.** HR roles (`hr_headoffice`, `hr_region`) have the Letters module but no `letters.*` permission, so they cannot be given letters. `docs/07` lists the holders as secretary and manager roles (which matches).

## Visitors

10. **Visitors tab for HR.** `hr_headoffice` and `hr_region` have the Visitors module, but every Visitors route also requires `role:receptionist`. They see the tab but get 403 on its pages.

## Assets

11. **`docs/assets-module.md`** predates MDM, audits, the replacement policy and the Summary page; the manual was written from the routes and components. It also says tokens are given with `php artisan tinker` and an email, which was not carried over.
12. **ICT Team permissions.** `manage_manufacturers`, `manage_models`, `manage_ip_ranges`, `manage_replacement_policy` and `correct_assignments` are not seeded to `ict_team`; only Super Admin has them. Confirm intended.

## Commercial

13. **SMS roles.** `docs/16` says `commercial_manager` gets nothing for SMS and `manage_sms_settings` is Super Admin only. In the seeded data `commercial_manager` has `send_sms`, `view_sms_balance` and `view_sms_reports` (but not `manage_sms_settings`).
14. **Customer details right.** `commercial.view_customer_details` is Super Admin only by default; Commercial Officer/Manager cannot see names, and so cannot use `{name}` in SMS.
15. **Commercial navigation.** The Commercial tab is available to `district_manager`, `chief_manager`, `regional_chief_manager` by default, with `view_*` only (no reader performance, no export).

## Health & Safety

16. **Anonymous reports.** `docs/health-safety-module-design.md` §8.21 says a fully anonymous route is "left"; the report form offers **Report anonymously** and CLAUDE.md describes `is_anonymous`. The design doc is out of date.
17. **Phase 4.** §8.20 "Phase 4 as built" is still a placeholder in the design doc although Settings, expiry register and exports exist.
18. **`hs_officer` / `hs_manager`.** Design doc says not `ict_assignable`; confirmed in data. `regional_chief_manager` holds `approve_closure` and `export_reports`.

## Finance / Audit

19. **Audit Manager and Suppliers.** The Audit Suppliers route needs `audit.view`, which the Audit Manager does not hold (only `view_reviewed`), so the manager has no Suppliers screen. Check intent.
20. **Finance Manager exports/prints.** Finance Manager holds `export` and `view_reports` but not `create_pv`; printing is open to anyone with `finance.view`.

## Documentation structure

21. `CLAUDE.md` still says the older docs (`APP_DOCUMENTATION.md`, `docs/`) predate Assets and Transport; this is still true for Assets, and `docs/18` and `docs/19` cover Transport scoping and catalogue only, not the screens.
22. `docs/17-g-water-build-prompt.md`, `17-g-water-phases-2-4-prompt.md` and `17-transport-region-scope-prompt.md` are build prompts rather than documentation; they can be moved to an archive folder.
