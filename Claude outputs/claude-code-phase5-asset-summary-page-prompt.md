# Prompt for Claude Code — Phase 5: Asset Summary / Analytics page

Paste into a Claude Code session at the `erp_project` repo root. Read `CLAUDE.md` first. This follows Phase 1 (condition, Age/Warranty/Assignment dashboard cards), Phase 2 (Damaged status, `ict_asset_transfers`), and Phase 3 (Replacement Forecast settings + bucketing) — grounded in `claude/ict-assets-analytics-roadmap.md` §9 in the project. **Run `git log --oneline -30` first and read `app/Services/Assets/AssetDashboardService.php` and `resources/views/livewire/assets/dashboard.blade.php` in full before touching anything** — this prompt assumes Phase 1 and Phase 3's cards exist on the dashboard today; if Phase 3 hasn't actually been run yet (check for an `IctAssetReplacementPolicy` model / a replacement-bucket method), build it first exactly per the roadmap doc's §7 prompt as a prerequisite, then continue below — don't invent a different design for it here.

## Goal

The Assets dashboard has accumulated a lot of analytical cards (KPI overview, district breakdown, allocation donut, Asset Age, Warranty Status, Assignment/unassigned, Replacement Forecast) and is getting crowded. Split it:

- **Dashboard stays the quick-glance landing view**: the six KPI cards, District Breakdown, the allocation donut, and the Unassigned-assets tile. Nothing else from the list below stays there.
- **New "Summary" page** (`assets.summary`, new sidebar entry right after Dashboard) becomes the home for deeper analytics, organized into pill tabs so related things are grouped instead of stacked in one long scroll.

## What moves to the Summary page (existing — do not rebuild, just relocate)

- Asset Age buckets (Phase 1)
- Warranty Status buckets (Phase 1)
- Replacement Forecast buckets (Phase 3)
- "Employees with the most assets" list (Phase 1)

**Relocate, don't duplicate.** These already live as methods on `AssetDashboardService` (or wherever Phase 3 put the replacement one) — call those same methods from the new page's component and delete their markup from `dashboard.blade.php`. Don't copy the query logic into a second place.

## What's new on the Summary page

1. **Manufacturers & Models** — devices grouped by manufacturer (count + percentage), and within each manufacturer, by model. Before building, verify: does `ict_asset_model_id` get set consistently across all three categories (Assets/Phones/Network), or mainly on the Assets category? Check `IctAssetModel`, `ManufacturersManager`/`ModelsManager`, and how the three category forms populate `ict_asset_model_id` — scope the breakdown to whatever's actually populated, and say in your summary if phones/network devices turn out to have little or no model data (that's a real gap worth flagging, not something to paper over).
2. **Maintenance** — pulled from `IctAssetMaintenance`: counts by `status` (open/in-progress/completed or whatever the real status vocabulary is — check the model), a "most-repaired assets" top list (reuse the repeat-repair count each asset already exposes, e.g. `$asset->maintenanceLogs()->count()`), and average turnaround time for completed maintenance (`completion_date` minus `created_at`, excluding nulls). Add a simple month-by-month volume chart (reuse whatever chart component `dashboard.blade.php` already uses for the donut — check if it also supports a bar/line series, don't introduce a new charting approach if one already exists).
3. **Reported Issues** — pulled from `IctAssetIssueReport`: counts by `issue_type` (network, password reset, BitLocker, etc. — read the real vocabulary off the Reporting screen, don't guess it), counts by `status` (open/resolved), and the same month-by-month volume chart pattern as Maintenance.
4. **"Needs Attention" banner** (above the tabs, not its own tab) — a small card/list combining three existing signals into one place: `condition = Poor`, `status = Damaged`, and assets with an unresolved `IctAssetIssueReport`. Top 10, each linking to that asset's edit/detail view. This is new logic (a small method combining three existing queries) but no new schema.

## Shared filter bar

Add a page-wide (not per-tab) filter for **district** and, where a tab has a time dimension (Maintenance/Reported Issues charts), a **date range** — bound via `#[Url]` the same way Phase 1 introduced query-string binding for the dashboard's filters, so a filtered Summary view is bookmarkable. Apply it consistently across every tab's queries, not just some.

## Tabs / navigation

- Check for an existing pill/tab/segmented-control component in `resources/views/components/ui/` before building a new one (grep for `tabs`, `pill`, `segmented`) — reuse it if it exists; if not, build a small `x-ui.tabs` (or similarly named) component following the visual language of the existing `x-ui.*` library (same border/radius/spacing tokens as `stat-tile`/`card`) rather than inventing a one-off style just for this page.
- Bind the active tab via `#[Url(as: 'tab')]` so a specific tab is linkable (e.g. someone can send a colleague straight to the Maintenance tab).
- Suggested tab order: **Lifecycle** (Age / Warranty / Replacement Forecast) · **Assignment** (Employees with most assets) · **Manufacturers & Models** · **Maintenance** · **Reported Issues**.

## Service organization

`AssetDashboardService` already carries a lot — rather than growing it further, put the three new aggregations (Manufacturers & Models, Maintenance summary, Reported Issues summary) in a new `App\Services\Assets\AssetSummaryService`, and have the new page's Livewire component call both `AssetDashboardService` (for the relocated Age/Warranty/Replacement/Assignment methods) and `AssetSummaryService` (for the new ones). Don't move the relocated methods' *implementation* into the new service — only the page that renders them changes; the logic stays where it is.

## Access

Same gate as everywhere else in this module — the existing `assets.*` route-group `role:super_admin,ict_team` middleware covers it. No new permission needed; this is read-only reporting, the same level as the Dashboard.

## Tests

Under `tests/Feature/Assets/`, cover: the Summary page's each tab renders the expected counts for seeded data (manufacturer/model breakdown, maintenance status counts and average turnaround, issue counts by type/status); the district + date-range filter narrows every affected tab's numbers consistently; the "Needs Attention" list surfaces exactly the assets matching any of the three conditions and no others; the tab query-string binding is bookmarkable (a direct GET with `?tab=maintenance` opens on that tab); the relocated cards no longer render on the Dashboard. Run `php artisan test --filter=Assets` when done.

## Explicitly out of scope for this prompt

- Exporting the Summary page (Excel/PDF) — flagged as a good follow-up once this ships, not built now.
- A manufacturer/model "reliability ranking" (issues or repairs per model, to spot a bad batch of devices) — a nice next step once this page's raw counts exist, not part of this pass.
- Repair cost, Cost analysis, Loss/damage — still unscheduled per the roadmap doc, unrelated to this page.

Summarize at the end: which tabs you built, whether the manufacturer/model data turned out to be populated across all three categories or mainly just Assets, and confirm the relocated cards were removed from (not duplicated on) the Dashboard.
