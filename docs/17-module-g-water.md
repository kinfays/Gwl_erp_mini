# G-Water module — design

Status: **built, Phases 1 to 4** (sections 11 to 14 list what was built and where it departs from this design). Phase 0 (Transport region scoping) is done, see `docs/18-transport-region-scoping.md`. Flag: `GWL_GWATER_MODULE_ENABLED` (default off).
Replaces the hand-written ledger and the Excel "PART 14" sheets used to track bottle and sachet water.

## 1. What the paper/Excel records show

From the four photos:

| Source | What it tracks |
|---|---|
| Handwritten "New Price" ledger | Date, description (bottle / sachets), quantity received and sold, unit price (25 bottle, 10 sachet), amount, payment, balance, B/F. A **price increment started 12/5/26**. |
| Excel "BOTTLE WATER – PART 14" | B/F 960 + purchases 550 = 1,510 packs on the left; waybill deliveries (e.g. 800 on 3/9/2026) on the right; **outstanding 710**. |
| Excel "SACHET WATER" | B/F 1,365; waybills 2901, 2923, 2931 (100 + 105 + 105 + 210 = 520); **outstanding 845**. |
| Excel "PAYMENT REQUEST" | Payment raised for 550 bottle packs x 26.00 = 14,300.00 (grand total new paid). |

Reading: stock is counted in **packs** (bottle pack = 15 bottles, sachet pack = 30 sachets), every delivery is tied to a **waybill number**, a **balance brought forward** carries across periods, **prices change on a date**, and money owed is raised through **payment requests** that settle earlier balances.

Not in the paper records but required now: fuel to collect water, loading and offloading labour, damaged packs, and profit and loss.

## 2. Decisions taken

1. **Each location sets its own prices and waybill numbers.** (As built, the 12 May 2026 bottle increase is also seeded in the placeholder list, see section 11.) Selling and purchase prices live in one dated price list (`gw_prices`) that the location's own G-Water users maintain on the **Prices** screen; nothing is hard-coded. Each sale and each purchase line freezes the price in force on its date, so a later change never rewrites history. **The amounts shipped with the module are dummy placeholders** (bottle pack sells 25 / costs 20, sachet pack sells 10 / costs 8, as a system default with no location) so the screens work on day one; every location is expected to enter its real prices, which then override the placeholder from their effective date. The real price history (e.g. the ledger's 12 May 2026 increase) is entered by the users, not seeded.
2. **Waybills are entered by the user.** Each purchase trip carries a waybill number typed by the recorder (free text up to 30 characters, required, unique within the location among non-voided trips). There is no central waybill sequence to maintain, and a duplicate is refused with the existing trip shown.
3. **Sales are recorded as daily totals** per product (not per customer), **paid at once or on credit** (changed after the first design, which had no credit sales): a credit sale names the customer who pays later and the money that comes in afterwards is recorded against it (section 16). There is still no customer table: the name is typed on the sale.
4. **Every location keeps its own books and sees only its own.** Head Office counts as its own location (it shares a region id with the Head Office district, so the scope is never "region alone"): a Head Office user sees Head Office records only, a regional user sees their region only, and only `super_admin` sees everything.
5. **Loading and offloading labour is paid in cash**, so it is a plain expense with no reimbursement. **Only fuel is reimbursed.**
6. Money is stored as integer **pesewas**; quantities are integer packs.

## 3. Data model

All tables prefixed `gw_`, numeric ids, `region_id` and `district_id` on every row (copied from the recording user's employee; together they give the Head Office-or-region scope), `created_by`, timestamps. Migrations guard with `Schema::hasTable/hasColumn` and seed permissions inside a migration (project convention).

- `gw_products` — `code` (`bottle`, `sachet`), `name`, `units_per_pack` (15, 30), `is_active`.
- `gw_prices` — `region_id` and `district_id` (**both NULL = the system placeholder default**, editable by `super_admin` only; otherwise the owning location), `product_id`, `effective_from`, `selling_price`, `cost_price` (both required), `note`, `created_by`. A location adds a new row for every change (selling, cost or both); lookup takes the location's own latest row with `effective_from <= date`, else the placeholder default. Rows are immutable (a mistake is corrected by a new row with the same or a later date, never an edit); a row dated in the past is allowed (so history can be entered) but only before the first sale or purchase line that would use it is posted for that date, otherwise it is refused with the affected entries listed. Seeded placeholder only: bottle 25 / 20 and sachet 10 / 8, effective 2026-01-01, no location.
- `gw_purchases` — one per collection trip: `purchase_date`, `waybill_no` (typed by the user, unique per location among non-voided trips), `supplier_ref`, `note`, `status` (`posted`/`voided`), and the **Transport link** (all nullable): `vehicle_id`, `driver_id` (user), `mileage_log_id`, `vehicle_expense_id` (the fuel row, unique), plus the fuel reimbursement fields listed in section 5.
- `gw_purchase_lines` — `purchase_id`, `product_id`, `packs`, `unit_cost` (pesewas).
- `gw_sales` — (`payment_type` cash/credit, `customer_name`, `customer_phone` on credit sales; receipts in `gw_sale_receipts`, section 16) one per region/product/day: `sale_date`, `product_id`, `packs`, `unit_price` (snapshot of selling price at entry), `amount`, `status`. Unique (region, product, date, voided = false); a second entry the same day is an addition line, not a silent overwrite.
- `gw_damages` — `damage_date`, `product_id`, `packs`, `stage` (`transit`/`loading`/`offloading`/`storage`), `unit_cost` snapshot, `reason`, optional `purchase_id`.
- `gw_expenses` — `expense_date`, `category` (`fuel`, `loading`, `offloading`, `other`), `amount`, optional `purchase_id` (trip cost), `payee`, `receipt_ref`, `note`, plus reimbursement fields: `paid_by_user_id` (staff who paid out of pocket), `reimbursement_status` (`pending`/`reimbursed`; set only on `fuel` rows, labour and other rows are `not_applicable`), `reimbursed_on`, `reimbursement_ref`. Fuel and labour are normally linked to a purchase trip; others stand alone. A fuel expense linked to Transport holds no amount of its own (see section 5).
- `gw_supplier_payments` — payment requests and payments to G Water: `request_date`, `reference`, `amount`, `paid_on` (nullable), `status` (`requested`/`paid`/`voided`).
- `gw_stock_movements` — **immutable signed ledger** (same idea as `hs_ppe_stock_movements`): `product_id`, `movement_date`, `type` (`opening`, `purchase`, `sale`, `damage`, `adjustment`), `packs` (signed), `source_type`/`source_id`. Balance may never go negative; a correction is an `adjustment` row.
- `gw_opening_balances` — one-off B/F per region: packs per product (e.g. bottle 960, sachet 1,365) and amount owed to G Water, dated. Posts the `opening` stock rows.
- `gw_settings` — per-region options, if any (kept small; overrides of `config/gwl.php`).

No hard deletes. A wrong entry is **voided** (reason required, audited) and the stock ledger gets the reversing row.

## 4. Rules and formulas

Closing stock (packs) = opening + purchases − sold − damaged ± adjustments.

Packs outstanding on waybills (as the Excel sheets show) = packs ordered/paid for − packs delivered on waybills. This is a report built from purchases vs payment requests, not a second stock.

Profit and loss for a period and region:

| Line | Formula |
|---|---|
| Revenue | Σ sales amount |
| Cost of water sold | Σ packs sold × weighted-average unit cost (rolling, per product) |
| **Gross profit** | Revenue − cost of water sold |
| Damages | Σ damaged packs × unit cost (written off at cost) |
| Fuel | Σ fuel expenses |
| Loading and offloading | Σ labour expenses |
| Other expenses | Σ other |
| **Net profit** | Gross profit − damages − fuel − labour − other |

Extra measures: landed cost per pack = (purchase cost + trip fuel + trip labour) ÷ packs received on that trip; margin %; break-even packs; damage rate (damaged ÷ received); trip cost per pack; amount owed to G Water = purchases at cost + opening owed − payments paid. Ratios are recomputed from sums, `null` on a zero denominator. The current period is "in progress" and labelled so.

One service owns each rule: `GwaterPricingService` (price lookup), `GwaterStockService` (ledger, lock, never negative), `GwaterProfitService` (the only P&L evaluator; screens and exports call it), `GwaterVisibility` (region scope — never filter in Blade), `GwaterWorkflowService` (every write: re-read `lockForUpdate`, authorise on that copy, audit, void).

## 5. Transport link and reimbursement

Fuel (and driver time) is already recorded by the Transport module, so G-Water **reads** it instead of re-typing it:

- On a purchase trip the recorder picks a **vehicle** (and driver) from Transport. The trip can point to the vehicle's **fuel `VehicleExpense`** (`expense_type = fuel`, same vehicle, dated on or around the trip) and to the **`MileageLog`** for the run, which gives distance and so **fuel and cost per km, and trip cost per pack**.
- The fuel figure in G-Water's P&L is the amount on that linked `VehicleExpense`. G-Water never writes to, edits or deletes Transport rows; it only holds the reference. If the linked expense is later changed in Transport, the P&L follows it and the trip shows a "changed in Transport" note. A fuel expense can be linked to **one** G-Water trip only (unique), so it is never counted twice.
- If the fuel was paid out of pocket and is not in Transport, the recorder enters a G-Water `fuel` expense instead (`vehicle_id` optional) and the same rule applies: one source per trip's fuel, never both.
- **Reimbursement** covers staff who paid out of pocket. For a fuel purchase linked to Transport, the amount comes from the `VehicleExpense`, and the reimbursement state sits on the trip (`fuel_paid_by_user_id`, `fuel_reimbursement_status`, `fuel_reimbursed_on`, `fuel_reimbursement_ref` on `gw_purchases`). For every other reimbursable cost it sits on the `gw_expenses` row. No copy of the Transport amount is ever created. A **Reimbursements** screen lists what is pending per person, marks it reimbursed with a reference, and shows totals. Marking reimbursed needs `gwater.reimburse`, and never the person who recorded the entry.
- Transport is now region-scoped (`vehicles.region_id` / `district_id`, Phase 0, `docs/18-transport-region-scoping.md`). G-Water must call `TransportScope::vehicles($user)` for its vehicle picker and `findVehicle()` for a chosen id (404 outside reach), and read fuel expenses and mileage logs through `VehicleExpense::visibleTo($user)` / `MileageLog::visibleTo($user)` (the `ScopedByVehicle` trait), never re-derive the rule in `GwaterVisibility`. A trip is refused if the vehicle, driver or linked fuel/mileage row is outside the actor's reach. Vehicles that were never placed are invisible to everyone but `super_admin`, so they cannot be chosen for a trip until given a location.
- Optional later: a "G-Water trip" tag on the mileage log's `trip_purpose`.

### 5.1 Phase 0: Transport region scoping (done)

Built and documented in `docs/18-transport-region-scoping.md`: a regional or district user sees only their region's vehicles (Head Office district excluded), Head Office staff see only Head Office vehicles, `super_admin` sees all, anything outside reach answers 404, and the `transport_manager` bypass is gone. G-Water depends on it only through `TransportScope` (see the bullet above).

## 6. Access and the G-Water role

- Module flag, `module_access` row, `GwaterVisibility` for location scope (own Head Office-or-region scope only; `super_admin` all). Another location's record answers 404.
- **New role `g_water`, display name "G-Water"** (created in a migration, like other roles, with `module_access` and the permissions below). It fits any location (regional, district or Head Office staff each work in their own scope). Flags: `ict_assignable = true` (confirmed: ICT may assign it inside their own scope), `is_protected = false`, `is_system = false`.
- Permissions: `gwater.view`, `gwater.record` (sales, purchases, damages, expenses), `gwater.manage_prices`, `gwater.view_reports` (P&L), `gwater.void`, `gwater.reimburse`, `gwater.export`. The `g_water` role gets all of them by default; `admin` and `super_admin` get them through their usual bypass. If you want to split duties later (recorder vs. approver), create a second role from these permissions on the roles screen; no code change.
- Rules that stay regardless of role: voiding needs a reason and is audited; the person who recorded a reimbursable expense cannot mark it reimbursed.
- Layered checks as elsewhere: route middleware, then a Livewire `mount()` and action re-check.

## 7. Screens (Livewire)

1. **Overview** — stock on hand per product, month sales, net profit, owed to G Water, low-stock and damage-rate flags.
2. **Daily sales** — add a day's packs per product; the selling price is pre-filled from the location's price list and shown. The recorder may not override it on the sale; to change a price, use the Prices screen.
3. **Purchases (trips)** — the user types the **waybill number**, then packs per product, unit cost (pre-filled from the location's price list), vehicle and driver from Transport, the linked fuel expense and mileage log, and loading/offloading, all on one form.
4. **Damages** — product, packs, stage, reason.
5. **Expenses** and **Reimbursements** — category, amount, receipt reference; pending reimbursements per person with a mark-as-reimbursed action.
6. **Payments to G Water** — payment requests and payments, balance owed.
7. **Stock** — ledger per product with running balance and the waybill-outstanding view.
8. **Profit and loss** — month/quarter/range, per product and combined, chart (Chart.js via its own Vite entry) and trip-cost table.
9. **Prices** — the location's own dated price list: add a new selling and/or purchase price per product with an effective date, see the history of each, and see which price is currently in force and whether it is still the placeholder default. Needs `gwater.manage_prices` (the `g_water` role has it).
10. **Export** — Excel and PDF of the P&L, stock and payments, through the existing export pattern; string cells only.

## 8. Phases

0. **Transport region scoping** (section 5.1): done.
(Phases 1 to 4 below are all built; sections 11 to 14 say how.)
1. Foundation: flag, permissions, products and prices, opening balances, purchases with trip costs, daily sales, stock ledger.
2. Damages, expenses, supplier payments, owed balance.
3. P&L, overview, charts, exports.
4. Reminders (low stock, unpaid requests) and import of past ledger lines.

## 9. Tests

Plain PHPUnit, inline fixtures. Cover: price lookup (a location's own row overrides the placeholder from its effective date; a sale the day before uses the old price, the day of uses the new; two locations with different prices do not affect each other; a location without its own row falls back to the placeholder), waybill uniqueness per location (a voided trip frees its number; the same number in another location is allowed), the Transport scope (section 5.1), stock never negative, void reverses stock, location isolation (404 across regions and between Head Office and a region), P&L arithmetic against a hand-worked month, damages and trip costs reduce profit, opening balance posts once, audit rows written.

## 10. Open questions

1. Which vehicles did the Transport backfill leave unplaced? G-Water trips cannot use them until a `super_admin` gives each a location (check the "Transport vehicle location backfill finished" log line).
2. Should recording and reimbursing later be split into two roles? (No code change needed.)
3. Should unsold stock ever be returned or transferred between locations? (Not in scope now.)

## 11. Phase 1 as built

Behind `GWL_GWATER_MODULE_ENABLED` (`config/gwl.php`, true in `phpunit.xml`); nothing was applied to the real database and nothing was committed. Run `php artisan migrate` (two migrations: tables, then permissions/role/module access/seed data).

**Where it is:** migrations `2026_10_18_000001_create_gwater_tables` and `2026_10_18_000002_seed_gwater_module_access`; models `Gw*`; `app/Services/GWater/` (`GwaterVisibility`, `GwaterPricingService`, `GwaterStockService`, `GwaterWorkflowService`, `GwaterSummaryService`, `Money`); Livewire `app/Livewire/GWater/` (Overview, DailySales, Purchases, StockLedger, Prices, OpeningBalances, Reimbursements) with the `ScopesGwaterByActor` trait; views `resources/views/livewire/gwater/`; routes `gwater.*` in `routes/web.php`; sidebar in `ErpNavigation::gwaterSidebar()`; seeder `GwaterRolePermissionSeeder`; tests `tests/Feature/GWater/`.

**Built:** products (bottle pack of 15, sachet pack of 30), the dated price list, trips (waybill unique per location among posted trips, one line per product at the cost in force, Transport vehicle/driver/mileage/fuel links, G-Water fuel when not in Transport, loading and offloading), daily sales, the immutable stock ledger with day-level non-negative check, stock corrections, the one-off opening balance, voids with reasons and reversing ledger rows, fuel reimbursement, audit rows for every write. `gw_damages`, `gw_expenses` (used already for a trip's fuel/loading/offloading) and `gw_supplier_payments` exist; the damages, expenses and payments screens are Phase 2.

**Decisions taken where the design left room (change them here and in the code together):**
1. **The 12 May 2026 bottle selling price (26.00, cost still 20.00) is seeded** as a third row of the placeholder list, next to the 25/10 selling and 20/8 cost rows dated 2026-01-01, because the ledger shows it (a sale on 11 May is 25.00, on 12 May 26.00). Section 2.1 said real history would be typed by users; a location's own rows still override all of it from their own date.
2. **Sales:** no database unique key per day; a second entry for the same product and day is another line (an addition), each voidable on its own.
3. **Purchase cost is not typed on the trip:** the line takes the cost price in force on the trip date and freezes it (the form shows it). A different cost is a new row on the Prices screen.
4. **Opening balance** is one header row (`gw_opening_balances`: date and amount owed) plus `opening` ledger rows for the packs; it posts once per location, to correct it void and post again (refused if its packs were already sold).
5. **super_admin** has no location: every screen shows a picker of districts, and writes go into the books of the chosen one. `admin` is not a G-Water user.
6. **Reimbursement:** the person who paid the fuel cannot mark it reimbursed either (stricter than the design, which names only the recorder). The "changed in Transport" note compares the Transport row's `updated_at` with the trip's creation.
7. **A trip's vehicle** must be based at the trip's own G-Water location, so a super_admin recording Head Office books cannot use a regional vehicle; vehicles never placed in Transport cannot be chosen by anyone but super_admin.
8. **Stock rule:** the balance at the end of every day must be zero or more (not just today), so a backdated sale or a void can be refused for a past day.
9. **Waybill:** refused when longer than 30 characters (never silently cut).
10. `gwater.view_reports` and `gwater.export` are created and given to the role but unused until Phase 3.

## 12. Phase 2 as built

Migration `2026_10_19_000001_create_gwater_phase2_tables` (adds `gw_expenses.vehicle_id`, `gw_supplier_payment_lines`, `gw_opening_balance_lines`; opening balances already posted get their lines from their ledger rows). Workflow methods in `GwaterWorkflowService`: `recordDamage`/`voidDamage`, `recordExpense`/`voidExpense`, `recordPayment`/`markPaymentPaid`/`voidPayment`; `GwaterSummaryService::owedStatement()` and `waybillOutstanding()`. Screens: Damages, Expenses, Payments to G Water (with the owed statement); the Stock screen gained the waybill-outstanding view. The Reimbursements screen was already built in Phase 1.

**Rules and decisions:**
1. **Damages** post a `damage` ledger row (the day-level balance rule applies), freeze the cost in force on their date, and may name a trip (the damaged packs of a product cannot exceed what the trip brought).
2. **Expenses:** fuel is counted once per trip: a trip that links Transport fuel, or already has a non-voided G-Water fuel expense, takes no second one (void it to replace it). `paid_by_user_id` is allowed on fuel only; loading, offloading and other costs are never reimbursable. A fuel expense that was already paid back cannot be voided. The optional vehicle goes through `TransportScope::findVehicle()` and must be based at the same location.
3. **Payments:** a request may carry the packs it was raised for (lines of product, packs, price per pack; the price defaults to the cost in force); the amount is then their total or must agree with it. The reference is unique per location among non-voided payments. A request becomes `paid` on a date not before the request; voiding a paid payment makes the amount owed again.
4. **Owed balance** = opening amount owed + every posted trip at its frozen line costs - payments marked paid, as a dated statement with a running balance (`owedStatement()`; `owedToGwater()` is its last line, optionally as of a date). Requests not yet paid are shown apart.
5. **Waybill outstanding** per product = packs brought forward as "paid for, not yet delivered" (a new field of the opening balance) + packs on non-voided payment requests - packs delivered on trips since the opening date. A negative figure means more arrived than was paid for.
6. **Opening balance lines** freeze the cost of the packs brought forward (cost in force on the opening date) for the profit and loss, which never reads the price list for history.

## 13. Phase 3 as built

`GwaterProfitService` (the only P&L evaluator), the **Profit and loss** screen (`gwater.pnl`, needs `gwater.view_reports`; month, quarter or custom range, per product and combined, trip-cost table, two Chart.js charts through `@assets @vite('resources/js/charts.js') @endassets`), the full **Overview** (stock with low-stock flags, month sales, net profit so far, owed to G Water, damage-rate flags), and **exports** (`GwaterExportService`, `GwaterExportController`, route `gwater.export`): Excel and PDF of the profit and loss, stock ledger, damages, expenses, reimbursements and payments, each needing `gwater.export` AND `gwater.view_reports`. No migration.

**Rules and decisions:**
1. **Cost of water sold** = packs sold x the rolling weighted-average cost of that product on the sale's day, carried over the whole history (opening stock at the cost frozen on its opening line, every trip line at its frozen cost; sales and damages leave at the running average; stock corrections move packs, not the average). A period's report therefore does not restart the average.
2. **Damages** are written off at the cost frozen on each damage row. **Fuel**: a trip that links Transport fuel counts the `VehicleExpense` amount on the trip's date, read live (so it follows a later change in Transport, with a "changed in Transport" note); G-Water fuel expenses count on their own date. Loading, offloading and other expenses count on their own date. Voided rows are ignored. No price is ever read from the price list for history.
3. **Net profit is a combined figure** (fuel, labour and other costs belong to trips); per product the statement goes down to gross profit and damages.
4. **Measures:** gross and net margin, break-even packs (damages + fuel + labour + other, over the average gross profit per pack sold), damage rate (damaged / received packs, per product), landed cost per pack and trip cost per pack for each trip, trip cost per km and fuel per km where a mileage entry is linked, and the amount owed at the end of the period. Every ratio is recomputed from sums and is `null` (shown "n/a") on a zero denominator.
5. A period that contains today is labelled **in progress** on the screen and in the exports.
6. **Caching:** the payload (arrays and scalars only) is cached for 5 minutes under a key made of the user's scope, the location's books version (`GwaterBooksVersion`, bumped after the commit of every workflow write), the dates and a fingerprint of the Transport fuel rows the trips read; it is never stale after an entry or a change in Transport.
7. **Exports** reuse the Commercial module's generic workbook (`CommercialReportExport` + `ReportSheet`, text cells stay text) and PDF page; one location per file (own, or `?district=` for super_admin only); a row cap (`gwater_export_max_rows` 5,000, `gwater_export_pdf_max_rows` 1,500) refuses with a message; every export writes an audit row (`gwater.export_<report>_<format>` with report, format, period, rows and location ids only).
8. New config keys: `gwater_export_max_rows`, `gwater_export_pdf_max_rows`, `gwater_low_stock_packs` (flag at or below, default 50), `gwater_damage_rate_flag_pct` (default 5).

## 14. Phase 4 as built

Migration `2026_10_20_000001_create_gwater_settings_and_reminder_tables` (`gw_settings`, `gw_reminder_states`). `GwaterSettings`, `GwaterReminderService` (command `gwater:remind`), `GwaterImportService` (+ `GwaterImportTemplateExport`), the **Settings** and **Import past records** screens, `GwaterWorkflowService::saveSettings()`.

**Settings:** `low_stock_<product code>`, `payment_overdue_days`, `reminder_repeat_days`, per location (Head Office, or a region without it), overrides of `gwater_low_stock_packs` / `gwater_payment_overdue_days` / `gwater_reminder_repeat_days`; a value equal to its default is stored as no row; saving needs `gwater.manage_prices` and is audited (old and new values, numbers only). The Overview's low-stock flag reads the location's value.

**Reminders:** `php artisan gwater:remind [--dry-run]`, daily at 07:55, registered in `routes/console.php` only while `GWL_GWATER_MODULE_ENABLED` is on (the scheduler must be running; the service also does nothing with the flag off). For each location with G-Water records: one notice per product at or below its threshold, and one for the overdue payment requests (requested and older than the overdue days). An item is reminded about at most once per repeat interval; with nobody who can record at the location nothing is sent or marked. Notices go through `GeneralDatabaseNotification` and carry only the product, the location and counts. Recipients: active staff based there who hold `gwater.record` (the developer super_admin is never told).

**Import of past records:** one .xlsx with the sheets Opening balance, Purchases, Daily sales, Damages, Expenses, Payments (template: headings only and a Read me, no example rows). Preview posts everything through the workflow inside a transaction that is rolled back; confirm re-reads the file and posts for real, all or nothing, or "valid rows only". Create-only (an existing opening balance, waybill or payment reference is refused), always into the importer's own location (super_admin: the one chosen), dates dd/MM/yyyy or Excel dates, amounts in cedis, prices never typed (a unit cost in the file must agree with the price list on the row's date; a sale or purchase with no price in force is refused), a damage or expense names its trip by waybill, at most `gwater_import_max_rows` rows (default 2,000) in all. Imported entries are indistinguishable from typed ones in stock, profit and loss and the owed balance (a test pins this).

**Open / not done:** the import takes no vehicle, driver, mileage or Transport fuel references (those links are made on the trip form); reminders go in-app only (no e-mail); the average-cost method is fixed (rolling weighted average); `gwater.view_reports` and `gwater.export` are held by the `g_water` role as a whole (split duties by making a second role on the roles screen).

## 15. Demo data (Accra West)

`Database\Seeders\Demo\GwaterDemoSeeder` (not part of `DatabaseSeeder`; never runs in production): about ten weeks of books for Accra West ending yesterday, posted through `GwaterWorkflowService` as the demo G-Water users, so everything is what the application itself produces. Run it after `php artisan migrate` and `php artisan db:seed`, with `GWL_GWATER_MODULE_ENABLED=true`:

```bash
php artisan db:seed --class="Database\Seeders\Demo\GwaterDemoSeeder"
```

It adds the Accra West people it needs (`GWA001` recorder and `GWA002` approver, both `g_water`; `GWA101` driver; `GWA102` plain employee with no G-Water access; `GWSA01` super_admin; password `12345`), a pickup in Transport, the location's own price list with a rise part-way, an opening balance, a trip every week (Transport-linked fuel, fuel typed in G-Water, fuel the driver paid out of pocket, some already paid back), daily sales Monday to Saturday, damages, other expenses, five payment requests to G Water (most paid, one overdue, one recent), one voided sale, and a sachet low-stock limit that makes the Overview flag it. It is deterministic and idempotent: if Accra West already has demo trips or any opening balance it adds nothing. Test: `tests/Feature/GWater/GwaterDemoSeederTest.php`.

## 16. Credit sales (added after Phase 4)

For customers who take water now and pay later. Migration `2026_10_21_000001_add_credit_to_gwater_sales` (`gw_sales.payment_type` / `customer_name` / `customer_phone`, table `gw_sale_receipts`); `GwaterWorkflowService::recordSale()` (payment type), `recordSaleReceipt()`, `voidSaleReceipt()`; `GwaterSummaryService::creditSalesOwing()`, `owedByCustomers()`, `owingByCustomer()`; the **Daily sales** screen; the Overview tile, the P&L cash rows and the `receivables` export.

**Rules and decisions:**
1. A sale is `cash` (the default; every earlier sale) or `credit`. A credit sale needs the customer's name (2+ characters; the phone is optional). Stock and revenue are exactly the same either way: revenue is recognised when the sale is made, and the price is the one in force that day, frozen.
2. Money received later is a **receipt** on the credit sale (`gwater.record`): as many as it takes, each at most what is still owed, not before the sale's day, not in the future, with an optional reference. A receipt is immutable; a wrong one is **voided** with a reason (`gwater.void`) and the customer owes it again.
3. A credit sale that has money against it cannot be voided until those receipts are voided; a voided or cash sale takes no receipt.
4. **What is owed** on a sale is its amount less the receipts not voided. The Daily sales screen shows it in the **Credit (owed)** column (Unpaid / Part paid / Paid in full, with each receipt listed and voidable), a **Who owes** card (grouped by customer, name typed in a different case or with spaces is one customer), a filter (all / credit / still owing) and "Receive payment" on each open debt. The Overview has an **Owed by customers** tile linking to the filtered list.
5. **Profit and loss:** revenue is unchanged; the combined figures also carry `credit_sales` (credit sales made in the period), `cash_collected` (cash sales in the period + receipts received in it) and `owed_by_customers` (as at the end of the period, counting only receipts up to that day). The cached payload is refreshed by every receipt like any other write.
6. **Exports:** the P&L export carries the three rows; a new **Owed by customers** report (`receivables`, Excel and PDF) lists, as at the end date, the customers who owe (by customer and sale by sale). It includes the customer's name and, on the by-customer list, the phone the staff typed: it is the point of the report.
7. **Personal data:** the customer's name and phone live on the sale. The audit rows, notices and logs never carry them (a test pins it); audit rows say only that a sale was `credit`, the amounts and the sale id.
8. **Import:** the Daily sales sheet takes optional `on_credit` (yes/no), `customer_name` and `customer_phone` columns. Payments received since are recorded on the screen, not in the file.
9. **Not done:** no due date or "overdue debt" reminder for credit customers yet; no credit limit per customer; no customer table (a customer is the typed name).

