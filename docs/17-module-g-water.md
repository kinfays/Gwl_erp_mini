# G-Water module — design

Status: **design, not built.** Flag: `GWL_GWATER_MODULE_ENABLED` (default off until Phase 1 ships).
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

1. **Selling price** starts at GH₵25 per bottle pack and GH₵10 per sachet pack. **Both selling and purchase prices can change**, so both live in one dated price list (`gw_prices`), never hard-coded. Each sale and each purchase line freezes the price that applied on its date, so a later change never rewrites history.
2. **Purchase cost** starts at **GH₵20 per bottle pack and GH₵8 per sachet pack** (the requester said "8 and 20"; read as sachet 8, bottle 20, since the reverse would sell sachets at a loss). The cost is required on every purchase line, defaulting to the price list. Opening margin: bottle GH₵5, sachet GH₵2 per pack, before fuel, labour and damages. **Confirm the bottle/sachet assignment.**
3. **Sales are recorded as daily totals** per product (not per customer). No credit sales, no customer table.
4. **Every region keeps its own books and sees only its own.** No Head Office exception except `super_admin`.
5. Money is stored as integer **pesewas**; quantities are integer packs.

## 3. Data model

All tables prefixed `gw_`, numeric ids, `region_id` on every row (copied from the recording user's employee), `created_by`, timestamps. Migrations guard with `Schema::hasTable/hasColumn` and seed permissions inside a migration (project convention).

- `gw_products` — `code` (`bottle`, `sachet`), `name`, `units_per_pack` (15, 30), `is_active`.
- `gw_prices` — `product_id`, `effective_from`, `selling_price`, `cost_price` (both required), `note`. A new row is added for every change (selling, cost or both); the latest row with `effective_from <= date` wins, so one product can have different selling and cost histories. Past rows are immutable. Seeded: bottle 25 / 20, sachet 10 / 8, effective 2026-01-01.
- `gw_purchases` — one per collection trip: `purchase_date`, `waybill_no` (unique per region), `supplier_ref`, `note`, `status` (`posted`/`voided`), and the **Transport link** (all nullable): `vehicle_id`, `driver_id` (user), `mileage_log_id`, `vehicle_expense_id` (the fuel row, unique), plus the fuel reimbursement fields listed in section 5.
- `gw_purchase_lines` — `purchase_id`, `product_id`, `packs`, `unit_cost` (pesewas).
- `gw_sales` — one per region/product/day: `sale_date`, `product_id`, `packs`, `unit_price` (snapshot of selling price at entry), `amount`, `status`. Unique (region, product, date, voided = false); a second entry the same day is an addition line, not a silent overwrite.
- `gw_damages` — `damage_date`, `product_id`, `packs`, `stage` (`transit`/`loading`/`offloading`/`storage`), `unit_cost` snapshot, `reason`, optional `purchase_id`.
- `gw_expenses` — `expense_date`, `category` (`fuel`, `loading`, `offloading`, `other`), `amount`, optional `purchase_id` (trip cost), `payee`, `receipt_ref`, `note`, plus reimbursement fields: `paid_by_user_id` (staff who paid out of pocket), `reimbursement_status` (`pending`/`reimbursed`/`not_applicable`), `reimbursed_on`, `reimbursement_ref`. Fuel and labour are normally linked to a purchase trip; others stand alone. A fuel expense linked to Transport holds no amount of its own (see section 5).
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
- Vehicle pickers show vehicles the actor may use. `Vehicle` carries no region column, so the build must decide the rule by checking how vehicles are assigned (assigned user's region) and keep it in one place (`GwaterVisibility`). **Open point for the build.**
- Transport Phase impact: none required in Transport. Optional later: a "G-Water trip" tag on the mileage log's `trip_purpose`.

## 6. Access and the G-Water role

- Module flag, `module_access` row, `GwaterVisibility` for region scope (own region only; `super_admin` all). Another region's record answers 404.
- **New role `g_water`, display name "G-Water"** (created in a migration, like other roles, with `module_access` and the permissions below). It is a regional role (fits regional and district staff; not Head Office-only). Proposed flags: `ict_assignable = true` so a regional ICT user can assign it inside their own scope, `is_protected = false`, `is_system = false`. Confirm both flags.
- Permissions: `gwater.view`, `gwater.record` (sales, purchases, damages, expenses), `gwater.manage_prices`, `gwater.view_reports` (P&L), `gwater.void`, `gwater.reimburse`, `gwater.export`. The `g_water` role gets all of them by default; `admin` and `super_admin` get them through their usual bypass. If you want to split duties later (recorder vs. approver), create a second role from these permissions on the roles screen; no code change.
- Rules that stay regardless of role: voiding needs a reason and is audited; the person who recorded a reimbursable expense cannot mark it reimbursed.
- Layered checks as elsewhere: route middleware, then a Livewire `mount()` and action re-check.

## 7. Screens (Livewire)

1. **Overview** — stock on hand per product, month sales, net profit, owed to G Water, low-stock and damage-rate flags.
2. **Daily sales** — add a day's packs per product; unit price pre-filled from the price list and shown, not editable without `manage_prices`.
3. **Purchases (trips)** — waybill, packs per product, unit cost (pre-filled from the price list), vehicle and driver from Transport, the linked fuel expense and mileage log, and loading/offloading entered on the same form.
4. **Damages** — product, packs, stage, reason.
5. **Expenses** and **Reimbursements** — category, amount, receipt reference; pending reimbursements per person with a mark-as-reimbursed action.
6. **Payments to G Water** — payment requests and payments, balance owed.
7. **Stock** — ledger per product with running balance and the waybill-outstanding view.
8. **Profit and loss** — month/quarter/range, per product and combined, chart (Chart.js via its own Vite entry) and trip-cost table.
9. **Prices** — dated price list for selling and purchase prices, with the history of each.
10. **Export** — Excel and PDF of the P&L, stock and payments, through the existing export pattern; string cells only.

## 8. Phases

1. Foundation: flag, permissions, products and prices, opening balances, purchases with trip costs, daily sales, stock ledger.
2. Damages, expenses, supplier payments, owed balance.
3. P&L, overview, charts, exports.
4. Reminders (low stock, unpaid requests) and import of past ledger lines.

## 9. Tests

Plain PHPUnit, inline fixtures. Cover: price effective dating (the 12/5/26 increase), stock never negative, void reverses stock, region isolation (404 across regions), P&L arithmetic against a hand-worked month, damages and trip costs reduce profit, opening balance posts once, audit rows written.

## 10. Open questions

1. Confirm purchase cost: bottle 20 and sachet 8 per pack. Also, is the 26.00 on the payment request a newer selling or cost price? It would be added as a dated price row.
2. Loading and offloading labour: paid in cash on the day, or also reimbursed? (Modelled as reimbursable like fuel; switch off if cash.)
3. `g_water` role flags (`ict_assignable`) and whether recording and reimbursing should later be split into two roles.
4. How to restrict the Transport vehicle picker by region (vehicles have no region column).
5. Should unsold stock ever be returned or transferred between regions? (Not in scope now.)
