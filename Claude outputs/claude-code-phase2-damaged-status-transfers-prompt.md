# Prompt for Claude Code — Phase 2: Damaged status + asset transfer history

Paste into a Claude Code session at the `erp_project` repo root. Read `CLAUDE.md` first. This follows the Phase 1 analytics prompt (age/warranty/assignment/condition — already shipped) and is grounded in `claude/ict-assets-analytics-roadmap.md` §5-§6 in the project; re-verify everything below against the actual current code/git log rather than trusting this prose, since the Assets module has been evolving fast.

## Context / decisions this implements

Four decisions were made on the open questions from the analytics roadmap:

1. The illustrative replacement-policy-by-years figures in the roadmap doc are **not real policy** — don't build a replacement-forecast feature off them. Not part of this prompt; mentioned only so you don't go looking for a policy table that doesn't exist.
2. Status vocabulary: add a new `Damaged` status paired with a free-text reason (e.g. "damaged-faulty power button"). `In Repair` specifically means the asset has been sent out for repair (not merely non-functioning) — no change needed to that status itself, just note it for anywhere status copy/help-text might currently be ambiguous. `Disposed` is the same concept as the existing `Retired` — do **not** add a separate Disposed status.
3. Vendor/procurement tracking: decided against. Not part of this prompt.
4. Movement/transfer history gets its own dedicated table: `ict_asset_transfers`. This is the core of this prompt.

## Current state (verify before relying on it)

- `App\Models\IctAsset::STATUS_ACTIVE / STATUS_IN_REPAIR / STATUS_RETIRED / STATUS_LOST` are the only statuses today. No `Damaged` constant exists yet.
- `App\Services\Assets\AssetRecordService::save()` is the single create/update path for all three categories (Assets/Phones/Network). It already auto-shifts `assigned_to_employee_id` → `previous_assigned_to_employee_id` on reassignment, and redacts `login_password`/`ssid_password` before auditing (`redact()`). Any new transfer-logging must hook into this method, not duplicate it elsewhere.
- Check whether the earlier "Replace Device" phone-swap prompt (`ict_assets.is_refurbished` / `replaces_ict_asset_id` + `IctAssetIssueReport` on the old device) was already implemented. **If it was**, fold transfer-logging into that flow too (log a `replacement`-type transfer row referencing both asset ids) rather than duplicating or replacing its existing logic — the two features are complementary, not competing designs for the same thing.
- `resources/views/components/ui/status-pill.blade.php` (or wherever status→tone mapping lives — grep for `STATUS_` usages) will need a tone for the new `Damaged` status. Grep every `STATUS_*` reference across the codebase (models, Livewire components, blade views, form `rules()`/options arrays, seeders/factories) before assuming you've found them all — this status touches more places than just the model constant.

## What to build

### 1. Damaged status

- Add `IctAsset::STATUS_DAMAGED = 'damaged'` (match the existing constant naming/casing convention) to the status list wherever it's enumerated (`STATUSES` array if one exists, dropdown/select options in Assets/Phones/Network forms, filter dropdowns on the list pages).
- Add a generic nullable column, e.g. `status_reason` (text, nullable) on `ict_assets` — guarded migration per `CLAUDE.md` conventions (`Schema::hasColumn` checks, no editing merged migrations). Keep it generic (not `damaged_reason`) since a reason could plausibly apply to other statuses later even though today it's only required for Damaged.
- Form validation: `status_reason` becomes required when `status === IctAsset::STATUS_DAMAGED`, on all three category forms (Assets/Phones/Network) — add a conditional rule (`required_if:status,damaged` or equivalent), not a blanket required.
- Status-pill / badge tone: give `Damaged` its own distinct tone (e.g. same family as a "danger/critical" tone already used elsewhere — check what tones `x-ui.status-pill` supports before inventing a new one).
- Make sure `AssetRecordService::save()` continues to work unmodified for the status_reason field — it should just pass through like any other form field already handled by the existing nullable-cleanup loop (add `status_reason` to that loop if an empty string should become `null` when status isn't Damaged).

### 2. `ict_asset_transfers` table

New guarded migration creating:

```php
Schema::create('ict_asset_transfers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ict_asset_id')->constrained('ict_assets')->cascadeOnDelete();
    $table->string('transfer_type')->index(); // assignment_change | status_change | district_change | replacement
    $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
    $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
    $table->string('from_status')->nullable();
    $table->string('to_status')->nullable();
    $table->foreignId('from_district_id')->nullable()->constrained('districts')->nullOnDelete();
    $table->foreignId('to_district_id')->nullable()->constrained('districts')->nullOnDelete();
    $table->foreignId('related_asset_id')->nullable()->constrained('ict_assets')->nullOnDelete(); // self-referential, used for 'replacement' type
    $table->text('reason')->nullable();
    $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('occurred_at')->useCurrent();
    $table->timestamps();
    $table->index(['ict_asset_id', 'occurred_at']);
});
```

Adjust column types/FK targets if the actual `employees`/`districts`/`users` table names or PK types differ from this assumption — verify first.

### 3. `IctAssetTransfer` model

- Standard Eloquent model, `belongsTo` relations for `asset()` (→ `IctAsset` via `ict_asset_id`), `fromEmployee()`, `toEmployee()`, `fromDistrict()`, `toDistrict()`, `relatedAsset()` (→ `IctAsset` via `related_asset_id`), `performedBy()` (→ `User`).
- Add the inverse `transfers()` `hasMany` relation on `IctAsset`, ordered by `occurred_at desc`.

### 4. `AssetTransferService::log()`

- New service, `App\Services\Assets\AssetTransferService`, with a method like `logChanges(IctAsset $existing, array $oldAttributes, IctAsset $fresh, ?int $performedByUserId): void`.
- Called from `AssetRecordService::save()`'s **update** branch only (not create — a transfer needs a prior state to diff against), after the existing update succeeds, inside the same DB transaction.
- Compares old vs new and writes **one row per changed dimension**, not one combined row:
  - `assigned_to_employee_id` changed → `transfer_type = 'assignment_change'`, `from_employee_id`/`to_employee_id` set, rest null.
  - `status` changed → `transfer_type = 'status_change'`, `from_status`/`to_status` set. If new status is Damaged, copy `status_reason` into `reason`.
  - `district_id` changed → `transfer_type = 'district_change'`, `from_district_id`/`to_district_id` set.
  - If the Replace Device flow exists and this call originates from it, a `replacement` type row should be logged on **both** the old and new asset, with `related_asset_id` pointing at the other — wire this explicitly from that flow rather than trying to infer "replacement" generically from attribute diffing.
- `performed_by_user_id` should come from the authenticated user (`auth()->id()`) at the point of the call — `AssetRecordService` likely doesn't currently take an acting-user parameter, so check how audit logging gets the current user today (`AuditLog::record()` probably resolves it internally) and follow the same pattern rather than threading a new parameter through every caller if avoidable.

### 5. Read-only "History" section

- On the Assets/Phones/Network edit or detail view (whichever each category currently has — check if there's a dedicated show/detail blade or if edit-in-place is the only view), add a read-only "History" panel listing that asset's `transfers()`, newest first: date, type, and a human-readable summary line per type (e.g. "Reassigned from Jane Doe to John Smith", "Status changed from Active to Damaged — faulty power button", "Moved from Ashanti to Greater Accra"). Use existing `x-ui.*` components (table or a simple timeline/list pattern — check what's already used for similar read-only history elsewhere in the app, e.g. audit log views, before inventing new markup).

### 6. Tests

Under `tests/Feature/Assets/`, cover:

- Changing `status` to Damaged without a `status_reason` fails validation; with one, succeeds and persists.
- Changing status, assignment, and district all in one update call logs three separate `ict_asset_transfers` rows, each with the right `transfer_type` and from/to values.
- No transfer rows are created on asset **creation** (only on update-with-a-change).
- An update that changes nothing relevant doesn't create spurious transfer rows.
- If Replace Device exists: performing a replacement logs a `replacement`-type transfer on both the old and new asset, each referencing the other via `related_asset_id`.
- The History panel renders the expected rows for a seeded asset with transfer history.

Run `php artisan test --filter=Assets` when done.

## Explicitly out of scope for this prompt

- No `Available` or `Disposed` status (Disposed = Retired, already covered).
- No replacement-forecast feature based on the illustrative policy-years figures.
- No cost/purchase-price analysis.
- No loss/damage financial tracking (e.g. cost-of-damage fields) — `Damaged` status + reason text is the full scope of "damage" here.
- No repair-cost tracking.
- No vendor/procurement tracking.
- No compliance/audit-trail tables beyond the `ict_asset_transfers` table itself and existing `AuditLog`.

If, while implementing, you find the Replace Device flow was never actually built (check git log / search for `replaces_ict_asset_id` before assuming), skip section on folding into it and just note that in your summary — don't build it as part of this prompt.
