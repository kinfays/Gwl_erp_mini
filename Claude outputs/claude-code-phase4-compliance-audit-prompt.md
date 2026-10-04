# Prompt for Claude Code — Phase 4: Compliance / physical-verification audit

Paste into a Claude Code session at the `erp_project` repo root. Read `CLAUDE.md` first. This follows Phase 1 (condition, age/warranty/assignment dashboard cards), Phase 2 (Damaged status, `ict_asset_transfers`/`AssetTransferService`), and Phase 3 (replacement-policy settings screen) — grounded in `claude/ict-assets-analytics-roadmap.md` §8 in the project. Re-verify against the actual current code/git log rather than trusting this prose — this phase deliberately reuses `AssetRecordService`/`AssetTransferService` for its optional correction-apply step, so confirm those are exactly as described before building on them.

## Design

**Concept.** A **physical audit** = one stock-check run: ICT (or whoever is assigned) walks a scope of assets (all of them, or filtered by device category / region / district) and confirms, for each one, whether what's on the ground matches what the system says. One run = one `ict_asset_audits` header row; each asset in scope gets one `ict_asset_audit_lines` row, snapshotted at the moment the audit starts so a mid-audit edit elsewhere doesn't quietly change what's being checked against. This mirrors the Credit Union module's deduction-batch pipeline (a batch header + line rows, each line matched/resolved individually, a computed rate at the end) — read `app/Services/CreditUnion/DeductionImportService.php` and `DeductionPostingService.php` for the shape to follow, not to copy domain logic from.

## Data model

Two migrations (or one — your call), `hasTable` guarded, explicit FK delete behaviour, never edit an existing migration:

```php
Schema::create('ict_asset_audits', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->string('scope_device_category')->nullable(); // null = all categories
    $table->foreignId('scope_region_id')->nullable()->constrained('regions')->nullOnDelete();
    $table->foreignId('scope_district_id')->nullable()->constrained('districts')->nullOnDelete();
    $table->string('status')->default('in_progress'); // in_progress | completed
    $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('started_at');
    $table->timestamp('completed_at')->nullable();
    $table->decimal('reconciliation_rate', 5, 2)->nullable(); // set on completion
    $table->text('notes')->nullable();
    $table->timestamps();
});

Schema::create('ict_asset_audit_lines', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ict_asset_audit_id')->constrained('ict_asset_audits')->cascadeOnDelete();
    $table->foreignId('ict_asset_id')->constrained('ict_assets')->cascadeOnDelete();
    $table->string('expected_status')->nullable();
    $table->foreignId('expected_assigned_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
    $table->foreignId('expected_district_id')->nullable()->constrained('districts')->nullOnDelete();
    $table->string('result')->nullable()->index(); // null = pending | matched | mismatch | not_found
    $table->string('actual_status')->nullable();
    $table->foreignId('actual_assigned_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
    $table->foreignId('actual_district_id')->nullable()->constrained('districts')->nullOnDelete();
    $table->string('actual_location')->nullable(); // free text, physical location as found
    $table->text('mismatch_reason')->nullable(); // required when result = mismatch or not_found
    $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('verified_at')->nullable();
    $table->boolean('correction_applied')->default(false);
    $table->timestamps();
    $table->index(['ict_asset_audit_id', 'result']);
});
```

Verify the real FK targets/column types for `regions`/`districts`/`employees`/`users`/`ict_assets` before relying on the snippet above — adjust if they differ.

## Service: `app/Services/Assets/AssetAuditService`

- `start(array $scope, string $title, int $startedByUserId): IctAssetAudit` — creates the audit row (`status = in_progress`, `started_at = now()`), then queries `IctAsset` with the given scope (device_category/region/district, all optional — no scope means every asset) and creates one `ict_asset_audit_lines` row per matching asset, snapshotting its *current* `status`, `assigned_to_employee_id`, `district_id` into the `expected_*` columns. Wrap in `DB::transaction`.
- `recordResult(IctAssetAuditLine $line, string $result, array $actual, ?string $reason, int $verifiedByUserId): void` — validates `$result` is one of `matched`/`mismatch`/`not_found`; `$reason` required when `mismatch` or `not_found` (throw `ValidationException`, same pattern `AssetRecordService` uses); sets the `actual_*` columns (ignore/null them when `$result === 'matched'`, since matched means actual = expected by definition), `verified_by_user_id`, `verified_at`.
- `applyCorrection(IctAssetAuditLine $line, int $actorUserId): void` — only valid when `result = mismatch` and not already applied (throw otherwise); pushes the line's `actual_status`/`actual_assigned_to_employee_id`/`actual_district_id` onto the real `IctAsset` via the **existing** `AssetRecordService::save()` — do not write a parallel update path. This means a correction automatically gets audited and automatically logs an `ict_asset_transfers` row via `AssetTransferService`, for free, because `save()`'s update branch already does both (confirm this is still true by reading the current `AssetRecordService::save()` before relying on it). Set `correction_applied = true` on the line.
- `complete(IctAssetAudit $audit): void` — guard: refuse (`ValidationException`) if any line still has `result` null (pending); compute `reconciliation_rate = matched_count / total_lines * 100`, rounded to 2 decimals; set `status = completed`, `completed_at = now()`.

## UI

New Livewire components under `app/Livewire/Assets/Audits/`, routes `assets.audits.*`. Add a new sidebar entry — check `App\Support\ErpNavigation::assetsSidebar()` for where the existing sections (Dashboard, All Assets, Maintenance, Reporting, Settings) sit, and add a top-level "Audits" or "Compliance" entry alongside Maintenance/Reporting, since this spans all three device categories rather than belonging under "All Assets."

- **List** (`assets.audits`): all audits, newest first — title, scope summary, status, reconciliation rate once completed, started-by/started-at. A "New Audit" action opens a form: title, optional scope (device category select, region select, district select — all optional), and a live count of "this will create N audit lines" as the scope changes, before submitting.
- **Run/review** (`assets.audits.show`): one audit's lines, grouped or filterable by device category, each row showing the asset's identity (serial/model/asset_type), its expected snapshot, and action buttons **Matched** / **Mismatch** / **Not Found** (the latter two reveal fields for actual status/assignee/district/location plus a required reason). A progress indicator ("N of M lines verified"). A visible **Apply correction** action per mismatched, not-yet-corrected line. A **Complete audit** button, disabled with an explanatory tooltip while any line is pending.
- Once completed: a read-only summary (matched/mismatch/not_found counts, the reconciliation rate) at the top of the show page.

## Export

Follow the Letters module's register-export pattern exactly: one service method (e.g. `AssetAuditService::exportRows(IctAssetAudit $audit): Collection`) feeding both a Livewire preview and the export, so they can never disagree. A controller (`App\Http\Controllers\Assets\AssetAuditExportController`), an Excel export class (`App\Exports\Assets\AssetAuditExport` — `FromCollection`, `WithHeadings`, `ShouldAutoSize`, plus `WithCustomValueBinder` or leading-character neutralisation to guard against spreadsheet-formula injection, since asset names/reasons are user text — the same concern the Letters register export guards against). A PDF via `new Dompdf($options)`, matching the visual pattern of whatever other PDF exports already exist in this codebase (grep for `new Dompdf` before inventing a header/footer layout). Only exportable once `status = completed`. Routes under the same `assets.audits.*` group, permission-gated per below.

## Permissions

Two new permissions, seeded the same `updateOrInsert` way as `assets.manage_ip_ranges` in the `2026_08_22` migration:

- `assets.manage_audits` — create/run/verify/complete. Grant to `super_admin` **and** `ict_team`, since physical stock-checks are routine ICT work, not policy-level like Phase 3's replacement-policy setting.
- `assets.export_audits` — grant to the same two roles.

State this grant explicitly in your summary in case it should be narrower.

## Audit trail

`AuditLog::record()` on audit creation, completion, and export (module Assets) — same pattern as everywhere else in this module. Do not duplicate audit-log rows for every line verification; the audit lines themselves are the detailed record, the same reasoning Phase 2 used for `ict_asset_transfers` vs. `audit_logs`.

## Tests

Under `tests/Feature/Assets/`, cover:

- Starting an audit with a scope creates exactly the matching assets' lines with the correct expected-snapshot values.
- Starting an audit with no scope includes every asset across all three categories.
- Recording a mismatch/not_found without a reason is rejected.
- Recording "matched" ignores/nulls any actual_* input.
- Completing an audit with a pending line is rejected.
- Completing computes the correct reconciliation_rate.
- Applying a correction updates the real `IctAsset` row, writes an `AuditLog` row (via `AssetRecordService`), and creates the expected `ict_asset_transfers` row(s) (via `AssetTransferService` — this is the integration point with Phase 2, so test it explicitly rather than assuming it falls out of reusing `save()`).
- The export only works on a completed audit and returns only that audit's lines.
- Both new permissions gate their respective routes/actions.

Run `php artisan test --filter=Assets` when done.

## Explicitly out of scope for this prompt

- Repair cost tracking, Cost analysis, Loss/damage extensions — none of those were chosen for this phase; each needs its own short scoping pass first, the way this one and Phases 1–3 got.
- A "found but not expected" (extra/unlisted asset) line type — out of scope; if the business turns out to need that, it's a follow-up, not a guess baked into this prompt.
- Recurring/scheduled audits (e.g. "every quarter automatically") — each audit is started manually for now.

Summarize at the end: what you built, confirm the `AssetTransferService` integration actually fired in your tests (don't just assume it), and call out the `ict_team` grant on both new permissions so it can be corrected if wrong.
