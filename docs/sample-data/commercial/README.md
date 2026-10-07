# Commercial module — synthetic sample data

Everything here is **made up**. The file layouts copy the real `rptReadingSummDate` and `rptBillingSumm_ExP` exports exactly (sheet structure, label positions, merged cells), but the people, counts and amounts are randomly generated. Reader names are `SAMPLE READER nn`, staff IDs are `90001`–`90024`. The route codes (e.g. `AMASAMAN 4601`) are the layout's own labels. Safe to keep in the repo; not real customer or staff data.

Every file reconciles to its own totals (checked): reader rows add up to the grand-total sheet; every billing route satisfies the receivable / payments / closing / billed / unbilled identities; district totals and `REPORT TOTALS` equal the sums of their routes.

## What is in the pack

| File | What it is |
|---|---|
| `sample_rptReadingSummDate_Jun-Sep.xlsx` | Meter-reading report, Jun–Sep 2026, region ACCRA WEST: 24 readers + the System Administrator account + grand-total sheet |
| `sample_rptReadingSummDate_Jul-Oct_weekly-refresh.xlsx` | The same report re-run a few days into October: Jul–Sep slightly revised (late reads), **Oct in progress** (about 19% of a month) |
| `sample_rptBillingSumm_NewService_Jun-Aug.xlsx` | Billing summary, New Service Customers, 3-month period Jun–Aug, 108 routes in 5 districts, domestic bands |
| `sample_rptBillingSumm_NewService_Sep.xlsx` | Same segment, single month (September) |
| `sample_rptBillingSumm_NewService_Jul.xlsx`, `…_Aug.xlsx` | Same segment, single months (July, August). With Sep these give **three single-month snapshots** for the Phase 3 trend and comparison. Each month is generated independently, so one month's opening balance does **not** equal the previous month's closing balance (fine for testing screens; not a realistic balance trend) |
| `staff-import-pack/1…5` | Staff-module import files (regions, districts, departments, job titles, employees) so the readers exist in your directory |

## Optional: make the readers match your directory

Do this first if you want to test the matched path. On the Staff import screen, import in order: `1_regions` → `2_districts` → `3_departments` → `4_job_titles` → `5_employees`. This adds the region **Accra West**, its five districts, a **Commercial** department, a **Meter Reader** job title, and **22 employees** (staff IDs 90001–90022). Readers **90023 and 90024 are deliberately left out** so you can test the unmatched path.

Use a local database only. Importing employees may create linked user accounts through the employee observer; set `MAIL_MAILER=log` first so no real mail goes out.

If you skip the pack, every reader will come in as "unmatched" (which is also a valid test of the resolve screen).

## Suggested test sequence and what to expect

1. **Reading Jun–Sep.** Reconciliation passes. 24 readers + 1 system account. With the staff pack: 22 matched, 2 unmatched (90023, 90024). Region `ACCRA WEST` resolves to `Accra West` (or resolve it once via alias if your name differs); the district names are on the file's filter line.
2. **Reading Jul–Oct weekly refresh.** Warns that Jul–Sep already exist and will be superseded (latest batch wins); October appears as **in progress** until the month ends. Coverage for Jul–Sep should move by a small amount versus step 1.
3. **Billing Jun–Aug.** Reconciliation passes, 108 routes, flagged as a multi-month period (usable for district and band comparisons, not for a monthly trend).
4. **Billing Sep.** Single-month batch, same segment, so a period-over-period comparison becomes possible in Phase 3.
5. **Re-upload any file.** Should be refused as a duplicate.
6. **Void the refresh batch.** Jul–Sep should fall back to the step-1 figures.

## Planted cases for the Phase 2 reading screens

| Reader | Pattern | What it should trigger |
|---|---|---|
| 90001 | High volume (about 4,100 visits/month), about 5% skip | top of the league table, best skip rate |
| 90002 | High volume, about 41% skip | **skip-rate outlier** |
| 90003 | New starter: nothing in Jun/Jul, 350 in Aug, 1,100 in Sep | zero months; consistency "not enough months" |
| 90004 | Normal Jun–Aug, **zero in Sep and Oct** | **inactive** reader (latest complete month = Sep) |
| 90005 | Tiny volume (about 160 visits), about 38% skip | **must NOT be flagged as an outlier** (below the minimum-visits rule) |
| 90006 | Falling every month (3,300 → 2,100) | month-on-month decline |
| 90023, 90024 | Normal readers not in the staff pack | ranked, flagged "Not in staff directory" |
| 00000 | System Administrator, 1 read in June | never appears in rankings or reader counts |

Region-wide: coverage runs about 94–96% for complete months; skip rate about 19% overall; strength grows about 0.25% a month from 71,200.
