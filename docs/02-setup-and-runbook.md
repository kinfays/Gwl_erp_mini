# Setup and Runbook

Last updated: 2026-09-26

## Stack

- PHP 8.4.1+ (production runs 8.5)
- Laravel 13
- Livewire 4.4
- Tailwind 4 + Alpine 3 + Vite 8 (Node.js 22+)
- Maatwebsite Excel 4 (PhpSpreadsheet 5)
- Dompdf 3.1

## Initial Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
npm install
npm run build
```

## Development Runtime

```bash
composer run dev
```

This starts:

- `php artisan serve`
- `php artisan queue:listen --tries=1 --timeout=0`
- `php artisan pail --timeout=0`
- `npm run dev`

## Scheduler

Visitor auto-checkout command:

```bash
php artisan gwcl:auto-checkout-visitors
```

Annual leave carry-over forfeiture (daily at 00:30; `--dry-run` previews without saving):

```bash
php artisan leave:forfeit-expired-carry-over
```

G-Water reminders (daily at 07:55; registered, and scheduled, only while `GWL_GWATER_MODULE_ENABLED` is on; `--dry-run` lists what would be sent without sending or recording anything):

```bash
php artisan gwater:remind
```

Scheduler setup is in `routes/console.php`:

```bash
php artisan schedule:work
```

## Important Config Keys

From `config/gwl.php` and `config/gwcl.php`:

- `GWL_AUTO_CHECKOUT_TIME`
- `GWCL_VISITORS_AUTO_CHECKOUT_TIME`
- `GWL_CARRY_OVER_EXPIRY_DAYS`
- `GWL_LEAVE_NOTIFICATION_POLL_SECONDS`
- `GWL_VISITOR_KIOSK_RESET_SECONDS`
- `GWL_MAX_IMPORT_FAILURE_PERCENT`

G-Water (`docs/17-module-g-water.md`; the module is off until `GWL_GWATER_MODULE_ENABLED=true`, and its reminders need the scheduler above to be running):

- `GWL_GWATER_MODULE_ENABLED` (default off): routes, navigation, screens and the `gwater:remind` schedule.
- `GWL_GWATER_EXPORT_MAX_ROWS` (5000) and `GWL_GWATER_EXPORT_PDF_MAX_ROWS` (1500): the longest Excel / PDF export.
- `GWL_GWATER_LOW_STOCK_PACKS` (50) and `GWL_GWATER_DAMAGE_RATE_FLAG_PCT` (5): the Overview flags.
- `GWL_GWATER_PAYMENT_OVERDUE_DAYS` (30) and `GWL_GWATER_REMINDER_REPEAT_DAYS` (7): the reminder defaults.
- `GWL_GWATER_IMPORT_MAX_ROWS` (2000): most rows in one past-ledger import file.

The low-stock limit and the two reminder intervals are defaults: each location may set its own on the G-Water Settings screen (an override is stored only when it differs from the default). After editing `.env` on a server run `php artisan config:cache` again.

## Test and Verification

```bash
php artisan test
php artisan route:list --except-vendor
```
