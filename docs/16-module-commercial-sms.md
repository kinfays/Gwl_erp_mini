# Commercial module — SMS to customers (Phase 7)

Commercial staff of **Accra West** can message Accra West customers by SMS from the ERP (through Arkesel): all customers, hand-picked customers, or a named group. They see the SMS balance in the app and every campaign has a delivery report. Nobody opens the Arkesel site for any of this.

Behind its own flag `GWL_COMMERCIAL_SMS_ENABLED` (default **false**; the Commercial module and the Customer List must also be on). Needs a **queue worker** and the **scheduler**.

Related: the customer data it reads is described in `docs/15-module-commercial-customer-list.md`; the design history is in `docs/commercial-module-design.md` (section 8.24).

## 1. What it does

| Screen | Who | What |
|---|---|---|
| **SMS** (Commercial sidebar) | any SMS permission | the balance (cached 5 minutes, throttled Refresh, low-balance banner) and links |
| **Send SMS** | `send_sms` | four steps: who (exact live count) → what (sender ID, template, text, parts and cost) → check (three masked sample recipients, cost against balance) → confirm (type the recipient count; or tick a box under 20) and send now or later |
| **Campaigns** | `send_sms` / `view_sms_reports` | the region's campaigns, cancel an open one |
| **Campaign report** | `view_sms_reports` | totals and percentages, timeline, failures by reason, recipients (masked, status filter), Excel export |
| **Templates** | `send_sms` | saved wordings with merge fields (no customer data) |
| **Settings** | `manage_sms_settings` (super_admin) | limits and defaults (the key, sender IDs and the region lock are in `.env`) |
| **Find customers** (existing) | `send_sms` + `view_customer_details` | tick customers, "Send SMS to ticked" |

## 2. Assumptions made (please confirm or correct)

Answered by the Commercial lead on 2026-10-10 unless marked *open*:

1. **Who is messaged:** the customer's **first** number only (`phone_primary`). The second number is never used (the audience code can do both, but nothing in the screens or the campaign service switches it on).
2. **Debtors:** balance **greater than** a minimum the sender types for each campaign; the starting value is the setting `commercial_sms_debtors_min` (GH¢ 100), editable by super_admin. A positive balance is taken as **owed** (the sign convention is still to be confirmed with the Commercial team: "to be confirmed" is printed on the screen). An arrears bucket can narrow it.
3. **No payment in N days:** starts on **60**; 30, 60, 90 and 180 are offered. Counted from **each district's file date** (not today), and includes accounts that never paid. Combine with the "Billing (ACTB)" group or the status filter to leave out disconnected accounts.
4. **Most valuable customers:** the **top N** (default 500) or **top X percent** (default 5) of **billing** accounts by *last bill amount*, ranked inside the district / route / category / status filters chosen; ties at the cut-off are all included. Both numbers are editable. *Open: this definition is a proposal.*
5. **Faulty meter group:** meter status F (faulty) or N (no meter).
6. **Status groups:** ACTB, ACTN, DISC and SUSP only. TRFR (transfer), VACN (vacant), DISO (disconnected others) and NFLO (no flow) were explained by the lead but are **still marked "meaning not confirmed"** in the customer-list lookups, so they are not groups and the status filter refuses them. Confirming them on **Commercial > Customer uploads > Settings** (the lookups screen) would make them usable as a filter; as groups they would need a decision on whether they bill.
7. **Accounts missing from the latest file** are left out by default; a switch on the screen includes them.
8. **Balance unreadable = send blocked** (a balance that cannot be read is "unavailable", never zero).
9. **One SMS per number per campaign:** accounts sharing a number are collapsed to the lowest account; the screen says how many share.
10. **Cost is an estimate:** recipients × message parts (GSM-7 160/153, Unicode 70/67). The real parts of each message are counted when it is built and added to "credits used".
11. **Merge fields:** `{account_no}`, `{balance}` (e.g. `GHS 1,234.50`, or `GHS 20.00 credit`; plain ASCII because the cedi sign is not in GSM-7) and `{name}` — **only for people who hold `view_customer_details`**. A message with merge fields is sent one request per customer.
12. **One campaign at a time per region** (the provider's rate limit). A second one waits.
13. **Who may use it:** staff whose employee record is in Accra West (and not Head Office) with the permission, plus super_admin. Global Admin is *not* let in unless they work in Accra West. Head Office staff are not let in even though Head Office shares Accra West's region id.
14. **Role:** the **Commercial Officer** (`commercial_officer`) gets `send_sms`, `view_sms_balance`, `view_sms_reports`. `commercial_manager` and every other role get nothing. `manage_sms_settings` is super_admin only.
15. **Sender IDs:** at most 11 characters, the first in `GWL_ARKESEL_SENDER_ID` is the default.
16. **Retention:** recipient rows (customer id + status) are kept for ever by default (`commercial_sms_recipient_retention_days = 0`); a purge keeps the campaign totals.

## 3. Arkesel: what is verified and what is not

Arkesel's own documentation (developers.arkesel.com) is a JavaScript app that could not be read while building this. Everything below was read from the source of a third-party Laravel SDK (`Parables/arkesel-sdk`) and must be checked against a real account:

* **Send:** `POST {base}/api/v2/sms/send`, header `api-key: KEY`, JSON `{sender, recipients: [..], message, scheduled_date?, sandbox?}`. Numbers are sent as `233XXXXXXXXX`.
* **Balance:** `GET {base}/api/v2/clients/balance-details`, same header. Read leniently: `data.sms_balance`, `sms_balance` or `balance`.
* **Status of one message:** `GET {base}/api/v2/sms/{id}` (path from the SDK; **the response shape is a guess**, read leniently from `status` / `data.status` / `delivery_status`).
* **Delivery webhook:** the SDK passes a `callback_url` per send. **We do not send one yet**: the webhook endpoint exists (`POST /webhooks/sms-arkesel?token=SECRET`), but its payload shape is **unverified**; the webhook and the poll both read a report as (message id, state, code) through the one parser in `ArkeselGateway`. Until a real report has been seen, rely on the poll (`commercial:sms:poll-delivery`).
* Every endpoint path is config (`GWL_ARKESEL_PATH_SEND` / `_BALANCE` / `_STATUS`). A non-2xx answer, a timeout or an unexpected shape is "unavailable" (balance) or "unknown" (send); never a zero balance and never a guess.
* If Arkesel turns out to offer no per-message status, set `GWL_COMMERCIAL_SMS_DELIVERY_REPORTS=false`: reports then say "accepted only" and the delivered column, the webhook and the poll are switched off.
* A **real** call has never been made from this code. Do one against the sandbox first (`GWL_ARKESEL_SANDBOX=true`: nothing is delivered or billed).

## 4. The rules that matter

* **Region lock — `Services/Commercial/Sms/SmsAccess` only.** The feature is on only when the three flags are on. Region names (`GWL_COMMERCIAL_SMS_REGIONS`, default ACCRA WEST) are resolved to ids at run time. Every screen, action, queued job, command and the webhook goes through it. The audience queries add the region condition themselves (district ids of the allowed region, plus `region_id`), so the UI is never the only guard. A campaign or template of another region answers **404**; another region's staff get **403**.
* **Groups — `SmsAudienceResolver` only** (never in Blade or Livewire). Every audience starts with the region lock, "not missing from the latest file", a valid first number, and one SMS per number. Candidate customers come from a single-table query on `commercial_customers` (keyset by id, one district at a time, no joins to the lookups); contacts are read by id list, or joined only for the exact preview counts.
* **Numbers only at send time, in memory.** Recipients are `customer_id` + `slot` + `status` + provider message id + error code. There is no phone column; the message **template** is stored, never the merged text. Nothing a number can be in (queue payload, audit row, log, exception, notification, rollup, recipients table) holds one: a test scans every table and every log line after full runs.
* **Never twice.** A recipient moves `pending → claimed → submitted → delivered | failed`, or ends `unknown`. The claim is one atomic `UPDATE ... WHERE status = 'pending'` carrying a token, made **before** the provider is called. A crash after the call leaves the row `claimed`; after `commercial_sms_unknown_after_minutes` it becomes `unknown`, shown as such and **never retried automatically**. A timeout after the request left is `unknown`; only a refusal is `failed`. One chunk of one campaign runs at a time (cache lock). A repeated dispatch, a restarted worker or a double click sends nothing twice (a submission token stops the double click).
* **Systemic failure stops the campaign:** when a whole chunk gets nothing accepted (refused, unreachable, timed out) the campaign stops as *failed* with a plain reason; it does not fail every remaining chunk.
* **Resumable:** the job payload is the campaign id only. `commercial:sms:run-due` (every minute) starts scheduled campaigns that are due and picks up any that stalled for 3 minutes.

## 5. Permissions

| Slug | super_admin | commercial_officer | everyone else |
|---|---|---|---|
| `commercial.send_sms` | yes | yes | no |
| `commercial.view_sms_balance` | yes | yes | no |
| `commercial.view_sms_reports` | yes | yes | no |
| `commercial.manage_sms_settings` | yes | no | no |

Seeded inside migration `2026_10_15_000001_seed_commercial_sms_permissions` and mirrored in `PermissionSeeder` / `CommercialRolePermissionSeeder`. Holding a permission is never enough: `SmsAccess` also checks the flag and the region. Exporting a report needs `view_sms_reports` **and** `commercial.export_reports`; names in lists and exports need `view_customer_details`, and phones are masked everywhere (no reveal path).

## 6. Audit

`AuditLog` rows (ids, counts, sender ID and the audience *rules*; never a number, a name or a message text): `commercial.sms_campaign_created`, `_scheduled`, `_started`, `_cancelled`, `_finished`, `_failed`, `_blocked`, `commercial.sms_template_saved`, `_deleted`, `commercial.sms_settings_changed` (and the general `commercial.settings_changed`), `commercial.sms_report_exported`, `commercial.sms_recipients_purged`. A hand-picked list is recorded as a count.

## 7. Tables

`commercial_sms_campaigns` (rules, template, counts, state, resume point, totals snapshot), `commercial_sms_recipients` (customer id, slot, status, claim token, provider message id, error code, timestamps), `commercial_sms_templates`. Migrations: `2026_10_15_000001` (permissions), `_000002` (tables), `_000003` (claim token, totals).

## 8. Config and `.env` keys

| `.env` key | Default | Meaning |
|---|---|---|
| `GWL_COMMERCIAL_SMS_ENABLED` | false | master switch |
| `GWL_COMMERCIAL_SMS_REGIONS` | ACCRA WEST | comma separated region names the feature is locked to |
| `GWL_ARKESEL_API_KEY` | – | **secret**; read from config only, never logged or shown |
| `GWL_ARKESEL_SENDER_ID` | – | approved sender IDs, comma separated, first = default |
| `GWL_ARKESEL_BASE_URL`, `_PATH_SEND`, `_PATH_BALANCE`, `_PATH_STATUS`, `_TIMEOUT` | see `.env.example` | endpoints |
| `GWL_ARKESEL_SANDBOX` | false | nothing delivered or billed |
| `GWL_ARKESEL_WEBHOOK_TOKEN` | – | secret in the webhook URL; empty = webhook off (404) |
| `GWL_COMMERCIAL_SMS_LOW_BALANCE` | 200 | credits |
| `GWL_COMMERCIAL_SMS_MAX_RECIPIENTS` / `_MAX_SELECTED` | 50000 / 2000 | limits |
| `GWL_COMMERCIAL_SMS_CHUNK_SIZE` / `_CHUNK_DELAY_SECONDS` | 200 / 2 | messages per request and pause |
| `GWL_COMMERCIAL_SMS_UNKNOWN_AFTER_MINUTES` | 15 | claimed → unknown |
| `GWL_COMMERCIAL_SMS_BALANCE_CACHE_SECONDS` | 300 | balance cache |
| `GWL_COMMERCIAL_SMS_POLL_DAYS` / `_POLL_BATCH` / `_POLL_PAUSE_MS` | 3 / 500 / 100 | delivery poll |
| `GWL_COMMERCIAL_SMS_DELIVERY_REPORTS` | true | false = "accepted only" |
| `GWL_COMMERCIAL_SMS_RECIPIENT_RETENTION_DAYS` | 0 | 0 = keep |
| `GWL_COMMERCIAL_SMS_DEBTORS_MIN`, `_NO_PAYMENT_DEFAULT_DAYS`, `_MOST_VALUABLE_TOP_N`, `_MOST_VALUABLE_TOP_PCT` | 100 / 60 / 500 / 5 | screen defaults |
| `GWL_COMMERCIAL_SMS_WEBHOOK_RATE_PER_MINUTE` | 600 | webhook throttle |

The limits and defaults (not the key, sender IDs, region lock or paths) can also be edited by super_admin on **SMS > Settings**; those are overrides stored like the other Commercial settings.

## 9. Commands and schedule (registered only while the SMS flag is on)

| Command | Schedule | Does |
|---|---|---|
| `commercial:sms:run-due` | every minute | starts due scheduled campaigns, picks up stalled ones |
| `commercial:sms:poll-delivery` | every 10 minutes | asks the provider about accepted messages of recent campaigns |
| `commercial:sms:check-balance` | daily 07:55 | low balance → in-app notice to the officers and super_admin of the region, at most once per `commercial_reminder_repeat_days` |
| `commercial:sms:purge` | daily 03:40 | deletes old who-got-what rows (retention), keeps totals |

## 10. Setting it up (once)

1. `php artisan migrate`, then make sure a **queue worker** (`queue:work`) and the **scheduler** (`schedule:work` / cron) run.
2. In `.env`: `GWL_COMMERCIAL_SMS_ENABLED=true`, `GWL_ARKESEL_API_KEY`, `GWL_ARKESEL_SENDER_ID` (the IDs Arkesel approved), `GWL_ARKESEL_WEBHOOK_TOKEN`; start with `GWL_ARKESEL_SANDBOX=true`. Run `php artisan config:cache` again if the server caches config.
3. Give the Commercial Officers of Accra West the role `commercial_officer` (they already carry it if they upload customer lists).
4. In the Arkesel dashboard, if it offers a delivery callback, point it at `https://<server>/webhooks/sms-arkesel?token=<the token>`.
5. Send a test campaign to one hand-picked customer (yourself) in sandbox mode, then without it.

## 11. Tests

`tests/Feature/Commercial/Sms/`: access and region lock (`SmsAccessTest`), the real gateway through faked HTTP (`ArkeselGatewayTest`), balance (`SmsBalanceTest`), audiences and groups (`SmsAudienceTest`), sending safety (`SmsSendingTest`), delivery, report, export and retention (`SmsDeliveryTest`), the screens (`SmsScreensTest`), and the privacy sweep (`SmsPrivacyTest`). The provider is always `Tests\Support\Commercial\FakeSmsGateway`; no test reaches Arkesel.
