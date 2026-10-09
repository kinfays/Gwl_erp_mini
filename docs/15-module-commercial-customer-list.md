# Commercial: Customer List (import + analytics)

Phase 6 of the Commercial module (see `docs/commercial-module-design.md`). Users upload the main platform's
`rptCustomerDetails` Excel export, one district per file, weekly or monthly. The system keeps the customer base current and
answers all-round questions about it. The company has millions of customers, so every choice below is judged by one test:
*does it stay fast with 5 to 10 million customer rows?*

Behind the flag `GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED` (default **true**; the Commercial module itself must also be on).

* Screens: **Customer List** (analysis, tabs A to I), **Find customers** (search and drill-down lists), **Customer** (one account),
  **Customer uploads** (+ a batch page with live progress), **Customer list: categories, statuses & cadence** (settings).
* Code: `app/Services/Commercial/Customers/*`, `app/Livewire/Commercial/Customers/*`, `app/Jobs/Commercial/*`,
  `app/Console/Commands/Commercial/*`, migrations `2026_10_09_1000xx_*`.
* Tests: `tests/Feature/Commercial/Customers/*` (synthetic workbooks only: `Tests\Support\Commercial\ReportWorkbooks::customerList()`).

## 1. What was measured before designing

On a real 18,525-row export of one district (structure only; no value from it is used anywhere in code, tests, docs or logs):

| Reader | 18.5k rows | Memory | Extrapolated to 500k rows |
|---|---|---|---|
| PhpSpreadsheet (what `ReportFileReader` uses) | 42 s | 312 MB | ~8 GB: **impossible** |
| OpenSpout 5 streaming reader | 179 s | 10 MB | its shared-string cache thrashes between temp files |
| **`XlsxStreamReader`** (this feature) | **2.8 s** | **10 MB, flat** | ~75 s, still 10 MB |

The sheet has about 74,000 merged cells, a "Document map" sheet whose declared range runs to column XFC (never opened), a multi-line
`REGION:/DISTRICT:` filter cell, and per route a group heading, a repeated column-header row, the customers and a
`<route> TOTALS :` row holding `Customer Count: N` and the balance. Everything is found by label, never by coordinate.

**The parser was run over the real sample (aggregates only, nothing written):** 18,427 customer rows in 30 routes; all 30 routes agree with
their own `TOTALS` row on both the count and the balance (to the pesewa); no duplicate account numbers; no malformed rows; every header row
has the required columns. The category codes in the file (19 distinct) and the status codes (ACTB, DISC, ACTN, SUSP, NFLO, TRFR, VACN, DISO) are
all in the seeded lookups; one row has a **blank status** (it becomes a pending status `?` with a warning). Meter status is W / F / N only.
Mobile numbers: 2,483 rows have none, 6,377 one and 9,567 several (97 contain a token that is not a valid number). 163 date cells are
implausible (zero-dates) and are counted as data-quality issues, not imported as dates. Meter factor is 1 for every row.

`XlsxStreamReader` streams the sheet XML with `XMLReader`, writes the shared strings once to a temp file with a packed offset table
(lookup is O(1) whichever string it is; the first 20,000 and the 2,000 most recent stay in memory), recognises dates from the cell
style, keeps the original row numbers, and opens sheets by **name**. OpenSpout stays in `composer.json` for the streamed **export** only.

## 2. Assumptions made where the brief had no answer (please confirm or correct)

1. **Production is MySQL** (the `.env` here says MySQL; tests and local development default to SQLite). The SQL is portable
   (`INSERT ... SELECT`, `CASE`, row-value comparisons, `GROUP BY`); the only driver-specific statements are the multi-table `UPDATE`
   and month truncation, behind `CustomerSql::isMysql()`. Both branches run in the test suite (SQLite) and in the benchmark (MySQL 8.4).
2. **The report has no date of its own**, so the uploader says what day the data is from ("Data as of") and whether the upload is
   weekly or monthly. A file older than the data already loaded for the district is refused.
3. **One district per file** (the `DISTRICT:` line). A route heading that names another district only warns.
4. **Arrears have no age in the file.** "Arrears aging" is therefore *how many of the customer's last bills are owed*
   (balance ÷ last bill): credit, nil, up to 1, 1 to 3, 3 to 6, 6 to 12, over 12 bills, and "owes with no usable last bill". The limits
   are `commercial_customer_arrears_bills` in `config/gwl.php`.
5. **Sign of a balance is unconfirmed**: positive is treated as owed (debit), negative as a credit, and every screen says "to be confirmed".
6. **Status codes TRFR, VACN, DISO, NFLO are unconfirmed**: stored with `meaning_confirmed = false`, shown everywhere as their raw code
   (never a guess), editable on the settings screen. ACTN, ACTB, DISC and SUSP are as you described.
7. **The category grouping is a proposal** (`group_is_proposed`): Domestic, Commercial, Industrial, Institutions/Government, Bulk & Tanker,
   Standpipe, Non-water/fee revenue, Unknown. Editable; tick *Confirmed* when agreed.
8. **Permissions**: `commercial.view_customer_analytics` (aggregates and lists without personal data) goes to the officer, manager and
   the three management roles; `commercial.view_customer_details` (names, addresses, masked phones/e-mails) goes to **nobody but
   `super_admin`** until someone grants it. Uploads use `upload_reports`, voiding `void_batches`, matching `resolve_matches`,
   exports `export_reports`, settings `manage_settings`.
9. **Only the newest upload of a district can be voided** (see 6.3). An older one cannot be undone exactly: upload a corrected file.
10. **Customers missing from a file are flagged, never deleted.** Contact details can optionally be purged after N days (default off).

## 3. Tables

All names are `commercial_*`. Fact tables store small integer ids; strings live in the lookups and in the contacts table.

| Table | Rows | Purpose |
|---|---|---|
| `commercial_customer_categories` / `_statuses` / `commercial_meter_statuses` | tens | lookups with small PKs, seeded in a migration. Unknown codes in a file become *pending* rows (`is_pending`) with a warning |
| `commercial_routes` | thousands | `(district_id, name)` unique, created on import |
| `commercial_customer_batches` | one per file | lifecycle, phase, resume points, counts, warnings, reconciliation |
| `commercial_customers` | **millions** | CURRENT STATE, one narrow row per account (`account_no` char(12) unique, kept as a string) |
| `commercial_customer_contacts` | millions | **PII**, 1:1, apart from the hot table: name, address, normalised mobiles (first and second number in their own indexed columns), e-mail, search columns |
| `commercial_customer_changes` | changes only | change log: status, category, meter status, route, district (a move), arrears bucket, missing, returned |
| `commercial_customer_undo` | one delta | pre-image of the rows the newest batch(es) of a district rewrote, for exact rollback |
| `commercial_customer_staging`, `_diff` | transient | landing table for the set-based merge, and the list of staged rows that need any work. Always cleaned |
| `commercial_customer_rollups` | routes x categories x statuses x meter states per batch | immutable facts at route grain |
| `commercial_customer_rollups_district` | the same without route | what every district-level dashboard reads |
| `_consumption`, `_quality`, `_connections`, `_debt_stats`, `_issue_accounts` | small | consumption bins / medians / outliers, data-quality counts, connections by month, debt concentration and medians, the accounts behind each quality count |

### `commercial_customers` columns and indexes (each index slows the bulk merge, so each has a query behind it)

Columns: `id` bigint, `account_no` char(12), `region_id`/`district_id`/`route_id`/`category_id`/`status_id`/`meter_status_id` (small ints),
`meter_no`, `connect_date`, `balance` and the bill/payment amounts as **integer pesewas**, last read/bill/paid dates and readings,
estimated and average consume, meter factor, `arrears_bucket` tinyint, `first_seen_batch_id`, `updated_batch_id`, `missing_since_batch_id`,
`attributes_hash` char(16) (xxh3 of the hot fields: cheap change detection).

| Index | Query behind it |
|---|---|
| `account_no` UNIQUE | merge join, lookup by account |
| `(district_id, category_id, status_id)` | filtered lists |
| `(district_id, route_id)` | the default list order (route, id) and route lists |
| `(district_id, balance)` | top debtors: `ORDER BY balance DESC, id DESC`, keyset |
| `(district_id, meter_status_id)`, `(district_id, arrears_bucket)` | the meter-status and balance-bucket filters of a district list |
| `(district_id, missing_since_batch_id)` | "not in the latest file" |
| `meter_no` | meter search, shared-meter lists |

Deliberate deviation from the brief: the brief listed `last_seen_batch_id`. It is `updated_batch_id` here (the batch that last **wrote**
the row), because the brief's other requirement, "re-import of an unchanged file does near-zero writes", forbids touching every
present row to stamp "seen". Presence is recorded the other way round: only an account that disappears gets `missing_since_batch_id`.

## 4. Import flow

```
upload (plain form post) -> file stored (private disk) -> batch "queued" -> ProcessCustomerListBatch (queue)
  locate    read only the FILTERS block; match region and district (unknown -> "needs a match", BEFORE any customer is read)
  stage     stream the sheet; ~2,000-row chunks into staging, each chunk its own transaction that also moves the resume point
  reconcile per route: rows read vs "Customer Count: N", balance vs the totals row; duplicates; malformed rows
  merge     one diff pass finds the rows that need work; undo, change log, update/insert; contacts only where their hash differs
  missing   accounts of the district the file no longer lists get missing_since_batch_id (never deleted)
  rollup    INSERT ... SELECT ... GROUP BY: route rollups, district rollups, consumption, connections, debt, issue lists, quality
  complete  staging and the uploaded workbook are deleted; imported/superseded labels recomputed
```

* **Blocked** (nothing changes): a route that does not add up to its own totals (GH¢1 slack on balances), duplicate account numbers in the
  file, a missing required column, a region the uploader may not load, a file older than the district's current data.
* **Only warns**: an unknown region/district (parks the batch), unknown category/status/meter codes (pending rows), a customer count that
  moved by more than `commercial_customer_count_change_warn_pct` (a partial export looks like that), malformed rows (counted, first 40 listed
  by row number, never by value), a route without a totals row.
* **Idempotent**: the same file (SHA-256) is the same batch while it is in flight or is still the district's newest data. A *different*
  file with identical content (the next week, nothing changed) is a new batch and writes **no customer rows** (verified by test).
* **Resumable**: `phase`, `staged_through_row` and `merge_cursor` live on the batch. A killed worker is run again and carries on; tests kill
  a job mid-staging and mid-merge and check nothing is lost or counted twice.
* **Memory is flat** in the file size (tested at 3,000 and 12,000 rows; benchmark below at 100,000).
* **Concurrency**: one batch per district at a time (cache lock); a second waits (`CustomerDistrictBusy`, the job releases itself).
* **Failures never leak customer data**: the exception shown/stored is the class, the SQLSTATE and the phase, not the driver's text
  (which can echo a value). With `APP_DEBUG` on, the driver text is added for developers.
* Needs a **queue worker** (`php artisan queue:work --timeout=7300`) and, for reminders/purge, the **scheduler**. The job timeout is 7,200 s.
  For an ad-hoc run: `php artisan commercial:customers:process {batch}`.
* The upload is a classic form post (Livewire's temporary upload is capped near 12 MB). Raise PHP `upload_max_filesize`/`post_max_size` and
  the web server body limit to at least `GWL_COMMERCIAL_CUSTOMER_IMPORT_MAX_MB` (default 200).

### 4a. Mobile numbers (Phase 6b)

The `Mobile` cell holds one or two numbers (in the real sample, never more than two): 12 digits each (`233` + nine), joined by space, slash, space. `CustomerValues::readPhones()` is the one place that reads it:

* **Separators**: `/ , ; | &`, a line break, or the words AND / OR / NA. `ext 12` / `x12` and what follows is ignored. Spaces *inside* a number never split it (`024 123 4567` is one number).
* **Forms repaired to `0XXXXXXXXX`**: `233...`, `+233...`, `00233...`, a lost leading zero (nine digits, also an Excel number), and a stray 0 after 233 (`2330...`, 13 digits; counted as "repaired").
* **Run-together numbers** (`0241234567 0207654321` with only a space, or no separator at all) are cut from the left as 12-digit `233...`, 10-digit `0...` or 9-digit pieces, and accepted only when **exactly one** way of cutting uses every digit; an ambiguous string stays invalid.
* **Placeholders** (nine digits after the leading 0 using two or fewer different digits, or a straight run such as `0123456789`) are invalid: they set `INVALID_PHONE`, are never stored, never searched, never "shared".
* Unique numbers are kept in cell order; a bad token does not discard the good ones. All numbers go to `mobiles` (comma list), the first to `phone_primary`, the second to **`phone_secondary`** (indexed). A third number would live only in `mobiles` (not searchable); the sample has none.
* A new quality bit `MULTIPLE_PHONES` marks customers with two or more valid numbers; it is part of the contact hash, so a change of only the second number rewrites only the contact row (no customers row, no change-log row, no undo row).

Where it shows: search by phone matches either number; the shared-mobile list and count treat a number as shared when it is on more than one **account** of the district as first or second number (one account listing a number twice does not count); lists show the first number masked and `+n` when there are more; the Excel list export puts every number, masked, in the one "Mobile (masked)" text cell joined by ` / `; the single-customer view shows all numbers masked until revealed (audited, ids only). Data quality gains "Two or more mobile numbers" (with a drill-down list, capped like the others) and "Reachable" (at least one valid number; its list is the inverse of "No mobile number"), also as district columns. Each import records one counts-only line in the batch warnings (cells with two or more numbers, numbers separated from a run, stray zeros removed, placeholders set aside). Contact retention purges `phone_secondary` with the rest of the row; a void does not roll contact details back.

## 5. Rollups, and why dashboards stay fast

Every dashboard, trend and comparison reads rollups (a few hundred rows per district at district grain), never `commercial_customers`.
Only drill-down lists, search and the single-customer page read the customers table, always with keyset pagination
(`(balance, id) < (?, ?)` row-value comparisons), an index-backed `ORDER BY`, no COUNT, and **one table** (the small lookups are resolved from the
page's few rows afterwards: joining them made MySQL pick hash joins and abandon index order, 1.7 s for a page that now takes 9 ms).

Rollup measures are sums of counts and pesewas; ratios are recomputed from sums, null on a zero denominator. Time-based counts (no read /
bill / payment in 30/60/90/180 days) are measured against the **batch's as-of date**, so a rollup stays true however old it gets.
Median and top-N facts are computed at import from the `(district_id, balance)` index (never a screen sorting millions of rows).

## 6. Change log, rollback and retention (explicit)

1. **Not a snapshot per upload.** A change-log row is written only when status, category, meter status, route, district (a *move*), or the
   arrears bucket changes, or an account goes missing / returns. An account's first appearance is `first_seen_batch_id` (no row).
2. **Rollups** are immutable per batch and kept (a few thousand rows per district per upload; at 200 districts weekly that is on the order of
   a few million small rows a year in total, compared with tens of millions for snapshots). There is no automatic pruning yet.
3. **Rollback.** Before an existing row is rewritten, its full pre-image goes to `commercial_customer_undo`; undo rows are kept only for the
   newest `commercial_customer_undo_keep_batches` (default **1**) batches of a district. Voiding the **newest** upload restores it exactly:
   accounts first seen in it are deleted (with their contacts), rewritten rows are put back from the undo table, "missing" marks are cleared, and
   its rollups, change-log and undo rows go. An older upload cannot be voided (its undo data is gone): upload a corrected file instead.
   Tested by comparing the whole customers table before and after. **Contact details are not rolled back** (a newer phone number stays).
4. An optional narrow monthly snapshot was considered and **not built**: the rollups already answer batch-over-batch questions, and the
   brief asked for a proposal only if warranted.

## 7. Privacy and access

* Personal data (name, address, mobile, e-mail) exists in three places only: the contacts table, the staging table (cleaned in a `finally`
  path, truncated when empty, purged after a week if a batch never finished) and the uploaded workbook (deleted as soon as the batch is
  finished or blocked). It is never in a rollup, an audit row, a queue payload, an exception, a log or a notification.
* `view_customer_analytics`: aggregates and lists with account number, route, category, status, meter, balance, dates. No names, no contact data;
  the search by phone / e-mail / name is not offered.
* `view_customer_details`: adds name and address; phones and e-mails are **masked in every list and export** (`024****567`, `a***@domain`).
  "Show full contact details" on the single-customer page unmasks one customer and is **audited** (customer id only).
* **Region scope** is applied in `ScopesCommercialByActor` (screens) and `CustomerScope` (jobs and commands; a test pins the two together)
  and inside every query: snapshots, rollups, lists, search, single customer, exports. A region/district/customer id typed into a URL that
  is outside the viewer's region is ignored or answers 404. The Livewire ids (`batchId`, `customerId`) are `#[Locked]`.
* **Exports**: need `export_reports` and the view permission. The summary is aggregates only. A list export has name/address columns only for
  a details holder, phones/e-mails masked even then, text forced to text cells (OpenSpout would otherwise turn a name starting `=` into a
  formula: found and fixed by a test), a row cap (`commercial_customer_export_max_rows`), and anything big or of unknown size is built in the
  background (`BuildCustomerListExport`), kept 7 days on the private disk and handed only to the person who asked. Every export is audited with
  filters and counts.
* **Retention**: `commercial_customer_contact_retention_days` (default **0 = off**). When set, `commercial:customers:purge` (scheduled
  03:30) deletes the contact rows of accounts missing from the files for that many days; the account row and its figures stay.
* The real export is **gitignored** (`rptCustomerDetail*.xlsx`); tests and fixtures are synthetic.

## 8. Analytics definitions (tabs of the Customer List screen)

| Tab | What it shows | Source |
|---|---|---|
| A. Overview | totals; billing (ACTB) vs active non-billing (ACTN) vs disconnected vs suspended vs other; mix by category group, category, status, meter status; per district and per route; customers per route; not-in-file, moved, new | district rollups (+ route rollups for one district) |
| B. Meters | working / faulty / no meter by district, route or category; faulty or no-meter customers still billing; share of billing customers on estimates; faulty-meter ageing by days since last read | rollups |
| C. Receivables | owed (debit) vs credit, average balance, arrears buckets, owed by status and category, owed by disconnected/suspended, debt concentration (top 1/5/10% of debtors), medians, top debtors (drill-down) | rollups + debt stats |
| D. Billing & collection | paid ÷ billed on the *last* bill/payment, average last bill by category, billed but unpaid for 30/60/90/180 days, zero-bill customers | rollups |
| E. Dormancy | no read / bill / payment in N days, never read / billed / paid, "ghost" candidates (billing account with no bill and no read in 180 days) | rollups |
| F. Growth & churn | new connections by month (24 months) and group, status migration matrix of an upload, reconnections (DISC to ACTB), net change, not in latest file, moved accounts | connections, change log, batches |
| G. Consumption | average consumption distribution, typical (median) per category, outliers above N x median, zero-consumption billed accounts, meter-factor anomalies | consumption |
| H. Data quality | counts and a drill-down list for: no mobile / e-mail / address / name, invalid phone, two or more mobile numbers, reachable (at least one valid number), future or implausible dates, UNKNOWN or unreviewed category, unconfirmed status, shared meter / mobile / e-mail, not in latest file; contact completeness per district | quality + issue lists |
| I. Trends & compare | period-by-period series, district league table with ranks, selection vs company | district rollups |

Filters: period, region (all-region viewers), district, route, category group, status, meter status, billing accounts only. Data-quality lists
hold at most 10,000 accounts per issue (the count is always exact) and are kept for the newest two uploads of a district.

## 9. Settings (editable)

On **Commercial settings > Customer list**: count-change warning %, outlier multiple, how many uploads per district keep rollback data,
contact retention days, weekly/monthly overdue days, export row cap and background threshold. On **Customer list: categories, statuses &
cadence**: category names and groups (and *Confirmed*), status meanings and the Active/Billing/Confirmed flags, meter status meanings, and each
district's expected cadence (weekly, monthly, not expected) stored in `commercial_settings` as `customer_cadence.district.{id}`. Codes new in a
file are flagged for review there.

Reminders: `commercial:remind-customer-uploads` (daily 07:50) tells the officers of a district's region when its newest upload is older than
its cadence's limit, at most once per `commercial_reminder_repeat_days`; the overdue districts also show on the uploads screen.

## 10. Config and env keys added

`GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED`, `_IMPORT_MAX_MB`, `_MERGE_CHUNK`, `_COUNT_CHANGE_WARN_PCT`, `_OUTLIER_MULTIPLE`, `_UNDO_KEEP_BATCHES`,
`_CONTACT_RETENTION_DAYS`, `_REMINDER_WEEKLY_DAYS`, `_REMINDER_MONTHLY_DAYS`, `_DEFAULT_CADENCE`, `_EXPORT_MAX_ROWS`, `_EXPORT_QUEUE_THRESHOLD`,
`_PAGE_SIZE` (all `GWL_COMMERCIAL_CUSTOMER_...`; see `.env.example`). `commercial_customer_arrears_bills` is config-only.

Migrations to run (in this order; nothing existing is edited): `2026_10_09_100001_create_commercial_customer_lookup_tables`,
`_100002_create_commercial_customer_tables`, `_100003_create_commercial_customer_rollup_tables`, `_100004_seed_commercial_customer_permissions`
(and, if not yet run, `2026_10_06_000001_create_commercial_settings_tables`). Composer: `openspout/openspout ^5.12` (run `composer install`).

## 11. Performance proof

`php artisan commercial:customers:benchmark` builds a **scratch** database (the name must be letters/digits/underscores, contain `bench`,
differ from the application's, and the command refuses to run MySQL from the test suite), generates real-layout `.xlsx` files with a streaming
writer and runs the real pipeline (initial load, a 10%-changed re-upload, an unchanged re-upload), bulk-seeds the remaining customers, builds
their rollups with the real service, times the dashboard and drill-down queries and prints `EXPLAIN` for the heaviest. The scratch database is
dropped afterwards (`--keep` to keep it, `--reuse` to run again on it, `--profile` to see where the database time goes). Results are written to
`storage/app/commercial-benchmark-report.json`.

```bash
php artisan commercial:customers:benchmark --driver=mysql --customers=2000000 --districts=20 --file-rows=100000
```

**Measured on this development machine (Windows laptop, MySQL 8.4 with default Laragon settings: 128 MB buffer pool, binary log on), 2,000,000 customers in 20 districts:**

| Pipeline (real code, real-layout .xlsx) | Rows | Seconds per 100k rows | Notes |
|---|---|---|---|
| Initial load | 100,000 | 190 (merge 120 of it) | pessimistic: 9 indexes and a 128 MB buffer pool; the 25k-row load took 102 s per 100k |
| Re-upload, 10% changed | 100,000 | 74 | merge 11 s per 100k; 10,000 rows rewritten, 90,000 not touched |
| Re-upload, unchanged | 100,000 | 68 | merge 6 s per 100k; **0 customer rows written** |
| Peak memory | 25k and 100k rows | 60 MB both | flat in the size of the file |

| Query (median of 3, 2M customers) | ms |
|---|---|
| Dashboard queries (overview, meters, receivables, collection, dormancy, growth, consumption, quality, trend, league) | 2 to 48 (target ~300) |
| Top debtors, first page / 40 keyset pages in a row | 4 / 655 (16 per page) |
| Route list, find by account, find by phone, shared-meter and missing-mobile lists | 3 to 10 (target ~500) |

`EXPLAIN` (in `storage/app/commercial-benchmark-report.json`) shows index use for the five heaviest: the dashboard reads the district rollups, top debtors uses a backward scan of `(district_id, balance)`, the route list `(district_id, route_id)`, the merge join `account_no` (unique) and the rollup build the district index. Rollup build costs about 21 s per 100k customers (issue lists 6 s, route rollups 4 s, debt 3 s, quality 3 s, consumption 3 s).

Where the targets were missed or are untested: a first load costs about 1.9 ms per row on this machine; raw InnoDB inserts here manage ~13,000 rows/s, so the pipeline is within an order of magnitude of the hardware but a tuned server (bigger buffer pool, SSD) should be markedly faster. The 2,000,000 run met the dashboard and drill-down targets; the 5,000,000 run (below) did not.

**5,000,000 customers in 50 districts, same machine and 128 MB buffer pool (the table and its indexes are several GB, far more than the buffer pool; the query timings below overlapped with a full test-suite run on the same machine, so they are pessimistic):**

| Query (median of 3, 5M customers) | ms | Target |
|---|---|---|
| Overview (company, all districts) | 260 | ~300 met, barely |
| Meter health, receivables, collection, dormancy, growth, consumption, trend, league | 11 to 132 | met |
| **Data quality** | **1,260** | **missed** |
| Top debtors page 1 / **40 keyset pages in a row** | 15 / **1,100** | page 1 met; 40 pages is ~27 ms a page |
| Route list, find by account, find by phone, shared-meter, missing-mobile | 7 to 15 | met |

The 20,000-row pipeline runs (made before the 5M rows were bulk-seeded, so on a near-empty table, not at 5M) took 61 to 105 s per 100k rows (initial load 105, 10% changed 63, unchanged 61; 0 customer rows written for the unchanged file) at a flat 60 MB. The pipeline itself has therefore not been timed against a 5M-row table.

**Missed, and not fixed yet:** (1) the rollup build is the problem at this size: 8,222 s for the 50 districts (165 s per 100k customers against 21 s at 2M), and 7,021 s of that was the `connections` step, which scans one district's customers for their connect month. That step is not slow at 2M, so something changes between 2M and 5M; it is most likely the 128 MB buffer pool (every district scan reads cold pages) or a bad plan after a bulk load without `ANALYZE TABLE`, but the run was not repeated with `EXPLAIN` on that statement, so the cause is unconfirmed. (2) Data quality reads small rollup tables (one row per issue and district), so its 1.26 s is unexplained; cold pages from the same buffer-pool pressure are a guess, not a finding. Before relying on 5M customers: re-run the benchmark on the production server's real MySQL settings (a buffer pool of a few GB), add the `connections` statement to the `EXPLAIN` list, and run `ANALYZE TABLE commercial_customers` after the first large load. Until then the 5M figures above are the honest numbers, and only the 2M ones met every target.

## 12. Operating notes

* Queue worker and scheduler are required (`queue:work --timeout=7300`, `schedule:work` / cron).
* After changing `.env` on a server that uses `config:cache`, run `php artisan config:cache` again.
* A district's data is replaced upload by upload; to correct a wrong upload, void it (if it is the newest) or upload a corrected file.
* `php artisan commercial:customers:purge --dry-run` shows what the housekeeping would delete.
* **Sample data for development:** `php artisan commercial:customers:sample --import` invents customer lists (names like "Sample Customer 00000123", numbers 024xxxxxxx, e-mails @example.test; nothing from a real export) for every district of a region (`--region="Accra West"`, `--customers=2500`, `--weeks=3`) and loads them as weekly uploads through the real pipeline, so the dashboards have trends, changes and "not in file" accounts. Without `--import` it only writes the files to `storage/app/sample-customer-files` (gitignored) for uploading by hand. It refuses to run in production. Because it runs on the real database, it is also the quickest MySQL check of the merge SQL (SQLite accepted a negative key in an unsigned column that MySQL rejected in the "missing" step).
