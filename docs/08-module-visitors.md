# Module: Visitors

## Scope

Visitor kiosk, receptionist logs, checkout control, and exports.

## Main Features

- Public kiosk (`/kiosk`) with guided flow
- Duplicate warning for active same-day phone entries
- Checkout code generation and self-checkout
- Receptionist checkout from today log
- Historical log filters by date range
- Excel and PDF exports
- Scheduled automatic checkout at close time

## Kiosk Steps

1. Name and phone
2. Employee host selection
3. Purpose
4. Signature and submit

## Checkout Modes

- `self`
- `receptionist`
- `auto`

## Export Scope

- Exports can run by single date or date range.
- Filename includes selected date window.

## Key Files

- `app/Livewire/Visitors/Kiosk.php`
- `app/Livewire/Visitors/TodayLog.php`
- `app/Livewire/Visitors/HistoryLog.php`
- `app/Http/Controllers/Visitors/VisitorExportController.php`
- `routes/console.php`
