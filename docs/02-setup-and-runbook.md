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

## Test and Verification

```bash
php artisan test
php artisan route:list --except-vendor
```
