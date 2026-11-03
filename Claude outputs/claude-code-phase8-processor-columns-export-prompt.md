# Prompt for Claude Code — Phase 8: Processor column, selectable columns, and Excel/PDF export (Assets module lists)

Paste into a Claude Code session at the `erp_project` repo root. Read `CLAUDE.md` first. This follows Phases 1–7, all shipped — grounded directly in the live code (`git log --oneline -30`) plus `claude/list-columns-and-export-design.md` and `claude/ict-assets-analytics-roadmap.md` §12 in the project. Confirm before changing anything: `app/Models/IctAsset.php` (has `UPS_ASSET_TYPES`/`supportsUps()`, Condition already removed), `app/Livewire/Assets/{AssetsList,PhonesList,NetworkList,IssueReports}.php` and their blade views, `routes/web.php`'s `assets.` group (note the existing `assets.summary.export.excel/pdf` and `assets.audits.export.excel/pdf` routes — this prompt extends that same family), `app/Http/Controllers/Assets/AssetSummaryExportController.php` and `app/Exports/Assets/AssetSummaryExport.php` (the Dompdf/Maatwebsite pattern to copy), `config/gwl.php` (the `*_export_max_rows` / `*_export_pdf_max_rows` naming convention), and `AssetsRolePermissionSeeder` (or wherever `assets.export_audits` is currently seeded — mirror it). If any of this has moved on, follow the code and tell me.

## Context

Three asks, bundled into one phase because the second and third build the first's infrastructure:

1. Add a **Processor** field to Assets (not Phones, not Network — computers only), shown as a form field and a list column.
2. Add a **column-visibility picker** ("select columns to show") to list views — built as reusable infrastructure, not an Assets-only hack, so any other module's list can adopt it later.
3. Add **Excel and PDF export** that exports exactly the columns currently selected — also built reusable, following the export pattern already established by the Summary and Audit exports.

This phase builds the reusable pieces **and** rolls them out concretely to the Assets module's four list views (`AssetsList`, `PhonesList`, `NetworkList`, `IssueReports`). Rolling the same reusable trait/component out to every other module's lists (UAC, Staff, Leave, Letters, Visitors, Transport, Credit Union, Commercial, Health & Safety) is **explicitly out of scope for this prompt** — once this ships, each of those is a small, mechanical follow-up (declare a column manifest, use the trait, add an export controller) that doesn't need to touch the shared infrastructure again.

## Part A — Processor column (Assets only)

`ict_assets.cpu_name` already exists in the DB and in `IctAsset::$fillable` (populated today only via the agent-report pipeline) but is not exposed in `AssetsList`'s form, and is not in `PhonesList`/`NetworkList` at all — correct, a phone or network device has no processor to track.

1. In `IctAsset`, add `public const PROCESSOR_ASSET_TYPES = ['PC', 'AIO', 'Laptop', 'Server'];` (computers with a CPU — excludes `PRT`/`PTC`, printers and photocopiers) and `public static function supportsProcessor(?string $category, ?string $assetType): bool`, mirroring `supportsUps()` exactly.
2. `AssetsList.php`: add `'cpu_name' => ''` to `$form`/`resetForm()`, map it in `openEdit()`, add `'form.cpu_name' => ['nullable', 'string', 'max:255']` to `rules()`. In `updatedForm()`, when `asset_type` changes to something outside `PROCESSOR_ASSET_TYPES`, clear `form.cpu_name` to `''` (mirror the existing UPS-clearing block). Pass `'formSupportsProcessor' => IctAsset::supportsProcessor(self::CATEGORY, (string) ($this->form['asset_type'] ?? ''))` to the view, recomputed the same way `formSupportsUps` already is.
3. `assets-list.blade.php`: add the field inside the `@if ($formSupportsUps)`-style guard, but for processor: `<x-ui.input label="Processor" wire:model.defer="form.cpu_name" placeholder="e.g. i7 13th Gen @ 1.6GHz, Ultra 7 155U" />`, gated by `@if ($formSupportsProcessor)`. Add a **Processor** column to the table (`<th>Processor</th>` and a cell showing `$asset->cpu_name ?: '—'` only `@if (App\Models\IctAsset::supportsProcessor($asset->device_category, $asset->asset_type))`, blank otherwise) — same visibility rule the UPS column already uses.
4. No migration, no new permission, no new audit code — `cpu_name` is already a column, and `AssetRecordService::save()` already covers auditing for every field that passes through it.

## Part B — Reusable column-visibility picker

Build this as **module-agnostic infrastructure**, not inside `App\Livewire\Assets`:

1. **`app/Livewire/Concerns/HasSelectableColumns.php`** (new trait, lives beside the existing module-agnostic `EnforcesModuleAccess` trait in the same folder). A component using it must implement `protected function columnManifest(): array`, returning an **ordered** map:
   ```php
   [
       'asset' => ['label' => 'Asset', 'default' => true, 'locked' => true],
       'type_model' => ['label' => 'Type / Model', 'default' => true],
       // ...
       'actions' => ['label' => 'Actions', 'default' => true, 'locked' => true],
   ]
   ```
   `locked` columns can never be hidden (every list keeps its identity column and its actions column visible — mirrors the reference screenshot, where the anchor column was shown ticked). The trait provides:
   - `#[Url(as: 'cols', except: '')] public string $colsParam = '';` — the selected column keys, comma-joined, bookmarkable like every other `#[Url]` filter in this module.
   - `visibleColumns(): array` — parses `colsParam` against `columnManifest()`, ignoring any key that isn't in the manifest (never trust the query string); falls back to every column whose `default` is `true` when `colsParam` is empty; always force-includes every `locked` column regardless of what's in the param.
   - `isColumnVisible(string $key): bool`.
   - `toggleColumn(string $key): void` — no-ops on a `locked` key; otherwise flips it in the selection and rewrites `colsParam` from the resulting `visibleColumns()` set (so the URL always reflects the *effective* selection, not a diff).
   - `resetColumns(): void` — clears `colsParam` back to defaults.
2. **`resources/views/components/ui/column-picker.blade.php`** (new, beside the other `x-ui.*` components) — a button ("Columns") that opens a checklist panel, same visual language as `x-ui.select`/`x-ui.checkbox`. Do **not** reuse `x-dropdown` as-is: its content `@click="open = false"` closes the panel on every click, which would close it after the first checkbox tick. Build a small dedicated `x-data="{ open: false }"` / `@click.outside="open = false"` wrapper that stays open across multiple toggles. Props: `:columns="$this->columnManifest()"` and `:visible="$this->visibleColumns()"`; each row is a `<label>` with a checkbox bound to `wire:click="toggleColumn('{{ $key }}')"` and `:checked="in_array('$key', $visible)"`, disabled (and visually muted, with a small "always shown" hint) when `locked`. Include a "Reset" link calling `resetColumns()`.
3. Add `<x-ui.column-picker />` to the toolbar of all four Assets list views, after the existing filter controls.
4. Wrap every non-locked `<th>` and its matching `<td>` in each of the four tables with `@if (in_array('key', $visibleColumns))` using a `$visibleColumns` array passed from `render()` (don't call the component method from inside a `@foreach` row loop — compute it once per render and pass it to the view, for performance). Locked columns need no conditional.
5. Column manifests to use for the four lists (same order as each table's current columns, `asset`/`device`/`title` and `actions` locked in every one):
   - **AssetsList**: `asset` (locked), `type_model`, `location`, `assigned_to`, `status`, `ups`, `processor` (new, from Part A), `actions` (locked).
   - **PhonesList**: `device` (locked), `type_model`, `numbers`, `assigned_to`, `status`, `actions` (locked).
   - **NetworkList**: `device` (locked), `type_model`, `network`, `credentials`, `location`, `status`, `actions` (locked). `credentials` defaults to **hidden** (`default => false`) even though it's toggleable — it's off by default so a casual "select columns" click doesn't put secrets on screen; the existing `assets.view_network_secrets` gate still applies underneath whenever it is turned on.
   - **IssueReports**: `title` (locked), `type`, `asset`, `location`, `status`, `date_solved`, `actions` (locked).

## Part C — Excel / PDF export of exactly the selected columns

Also module-agnostic infrastructure, plus its concrete use on the same four lists.

1. **`app/Exports/ListExport.php`** (new — deliberately at the `app/Exports/` root, not under an `Assets/` subfolder, since every module reuses this one class; note the exception to the "one export class per report, namespaced per module" convention for this shared file only). `implements FromCollection, ShouldAutoSize, WithHeadings, WithCustomValueBinder`, constructed with `(array $headings, Collection $rows)` where `$rows` is already a collection of plain arrays in heading order — the caller (each export controller) builds those from its own query and its own column manifest, this class just renders them. Use a string value binder exactly like `LetterRegisterExport`/`AssetSummaryExport`'s established pattern, so a cell beginning with `=`/`+`/`-`/`@` is never treated as a formula — prove it with a test.
2. **`resources/views/exports/list-pdf.blade.php`** (new, shared) — a generic table PDF: title, a one-line filter/column summary, generated time, row count, "Prepared by / Checked by" lines (same footer as every other PDF export in this app), then a table of `$headings/$rows`.
3. For **each** of the four lists, add an export controller under `app/Http/Controllers/Assets/` (`AssetsListExportController`, `PhonesListExportController`, `NetworkListExportController`, `IssueReportsExportController`), each with `excel()`/`pdf()`:
   - Re-run the same scoped query the Livewire list uses (reuse `ScopesAssetsByActor`/`ScopesReportsForActor` — do not duplicate the scoping logic), apply the same filters the list currently supports (read them from the query string the same way the Livewire component's `#[Url]` properties do), and respect a `?cols=` param the same way `HasSelectableColumns` parses it (reuse `visibleColumns()`'s logic — pull it into a small static/shared helper so the controller and the Livewire trait call the same parsing code rather than two copies).
   - **`NetworkList`'s `credentials` column must never appear in an export, even if selected** — strip it out when building the export's headings/rows regardless of the `cols` param, and say so in a one-line comment. Exported files are downloaded and forwarded; a password column in a spreadsheet is a much bigger leak than one on-screen behind a reveal toggle.
   - Row cap before building rows: new config entries, following the `commercial_export_max_rows` naming exactly — `assets_list_export_max_rows` (env `GWL_ASSETS_LIST_EXPORT_MAX_ROWS`, default 5000) and `assets_list_export_pdf_max_rows` (env `GWL_ASSETS_LIST_EXPORT_PDF_MAX_ROWS`, default 1500) in `config/gwl.php`. Over the cap: redirect back with a message naming the limit (suggest Excel if PDF is what hit it), no file, no audit row — same behaviour as Commercial.
   - `AuditLog::record('export_<list>_excel'|'export_<list>_pdf', Permission::MODULE_ASSETS, '<table>', null, null, ['filters' => ..., 'columns' => ..., 'rows' => $count])`, same shape as `AssetSummaryExportController::audit()`.
4. Routes, in the existing `assets.` group in `routes/web.php`, right beside each list's existing route (mirror `summary.export.excel`/`.pdf`'s placement and naming exactly):
   - `/assets/export/excel` → `assets.export.excel`, `/assets/export/pdf` → `assets.export.pdf` (full names `assets.assets.export.excel`/`.pdf`, matching how `assets.summary.export.excel` is already nested under the `assets.` group).
   - `/phones/export/excel` → `phones.export.excel`, `/phones/export/pdf` → `phones.export.pdf`.
   - `/network/export/excel` → `network.export.excel`, `/network/export/pdf` → `network.export.pdf`.
   - `/reports/export/excel` → `reports.export.excel`, `/reports/export/pdf` → `reports.export.pdf`.
5. Permissions: add `assets.export_inventory` (gates the three inventory-list exports — Assets/Phones/Network — mirroring how `assets.view_inventory` already gates their index routes) and `assets.export_reports` (gates the Issue Reports export, mirroring `assets.manage_reports`). Seed them the same way `assets.export_audits` is currently seeded — read that seeder entry first and mirror its role grants, don't invent a new grant pattern.
6. On each list's toolbar, next to the new column picker, add **Export Excel** / **Export PDF** links (only rendered when the actor holds the relevant export permission) pointing at the matching route, carrying the current filters and the current `cols` selection as query params — so what the user exports is exactly what they're looking at.

## Explicitly out of scope for this prompt

- Rolling `HasSelectableColumns` / `ListExport` out to any module other than Assets — flagged above as the natural next step, not built here.
- Persisting a user's column choice beyond the bookmarkable URL (e.g. a saved-per-user preference in the database) — out of scope unless asked for later.
- CSV export, or a background/queued export path for very large result sets (the existing row-cap-and-redirect pattern is the only guard in this phase, matching every other export in this app).
- Making `IPv6 Address` / `Controller Connection` real fields — nothing in this app currently tracks those; if that was inspired by an external reference screenshot, it wasn't part of the three numbered asks and isn't built here.

## Tests

Under `tests/Feature/Assets/`: Processor — field only persists for `PC`/`AIO`/`Laptop`/`Server`, is cleared when `asset_type` changes away from those, and the column only renders where it applies. Columns — `visibleColumns()` defaults correctly with no `cols` param, ignores an unknown/tampered key in `cols`, never drops a `locked` key even if the param tries to; `credentials` defaults hidden on `NetworkList`. Export — Excel and PDF each return only the selected columns' headings and matching row data; `NetworkList`'s export never includes `credentials` even when `cols` explicitly asks for it; a filtered list exports only the filtered rows; row cap redirects with no file over the limit; a cell value of `"=1+1"` round-trips as a string in the generated `.xlsx` (read the file back in the test, per the established pattern); audit rows are written with the right action/filters/columns/row count; export routes 403 without the new permissions. Run `php artisan test --filter=Assets` when done.

Summarize at the end: which column keys ended up locked vs. toggleable on each of the four lists (confirm they match §B.5), and confirm the `credentials` column never appears in a Network export regardless of the `cols` param sent.
