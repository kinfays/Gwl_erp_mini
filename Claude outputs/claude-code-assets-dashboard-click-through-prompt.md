# Prompt for Claude Code — Dashboard click-through filtering

Paste this into a Claude Code session at the `erp_project` repo root. Read `CLAUDE.md` first. This is grounded in the Assets module as it exists right now (the category split into Assets/Phones/Network, `AssetDashboardService`, the `x-ui.*` component library) — not the earlier pre-redesign version, so don't assume anything from an older prompt still applies without checking the current file.

## Goal

On the Assets dashboard (`resources/views/livewire/assets/dashboard.blade.php`, backed by `App\Livewire\Assets\Dashboard` and `App\Services\Assets\AssetDashboardService`):

1. Each of the six KPI tiles (Computers, Laptops, Printers, Phones, Servers, Network Devices) should link to the matching inventory list, pre-filtered to that category.
2. Each row in the **District Breakdown** table should link to the Assets list, pre-filtered to that district.

Both should work as plain navigable links (bookmarkable, shareable, work on a hard page load) — not only as a client-side Livewire action — because a dashboard tile is exactly the kind of link someone pastes into a chat message or re-opens later.

## Current state (verified)

- `x-ui.stat-tile` (`resources/views/components/ui/stat-tile.blade.php`) already supports an `href` prop — passing one turns the tile into an `<a>` automatically. No component change needed there, just pass `:href="..."` to each tile in `dashboard.blade.php`.
- `AssetDashboardService::CARD_TYPES` (protected) is the authoritative mapping from dashboard card key to underlying `asset_type` values:
  ```php
  'computers' => ['PC', 'AIO'],
  'laptops' => ['Laptop'],
  'printers' => ['PRT', 'PTC'],
  'phones' => ['POS', 'SIM', 'Ph'],
  'servers' => ['Server'],
  'network' => ['RT', 'SW', 'AP', 'MiFi', 'P2P', '4GRT'],
  ```
  Make this public (or add a public accessor) so the list components can reuse the exact same mapping instead of re-declaring it — two copies of this array drifting apart would quietly break the click-through.
- Three separate pages/components own the three categories: `App\Livewire\Assets\AssetsList` (`device_category = asset` — covers computers, laptops, printers, servers), `PhonesList` (`device_category = phone`), `NetworkList` (`device_category = network`), routed as `assets.assets`, `assets.phones`, `assets.network`.
- `AssetsList` already has `public string $assetType = ''` (exact match) and `public string $districtId = ''` (exact match) filters, applied in `render()`. `PhonesList` and `NetworkList` already have `$assetType` but **no district filter at all** today.
- None of the three list components use Livewire's `#[Url]` attribute (query-string binding) anywhere — this repo has no existing usage of it. This will be the first.
- The whole `assets.*` route group requires `role:super_admin,ict_team` — no permission/role changes are needed for this feature.

## What to build

### 1. KPI tile links

- **Computers / Printers** (multi-type categories): `AssetsList`'s existing `$assetType` is an *exact match* on one `asset_type`, which can't express "PC or AIO". Add a new bindable property, e.g. `public string $category = ''` (`#[Url(as: 'category')]`), distinct from `$assetType`. When set, filter `whereIn('asset_type', $types)` using the **same** `AssetDashboardService::CARD_TYPES` mapping (made accessible per above) for `$category`'s key. Link: `route('assets.assets', ['category' => 'computers'])` / `['category' => 'printers']`.
- **Laptops / Servers** (single-type categories): simplest to just set the existing `$assetType` directly via `#[Url(as: 'type')]` — `route('assets.assets', ['type' => 'Laptop'])` / `['type' => 'Server']`. Either reuse `$category` for these too (for consistency) or use `$assetType` directly — your call, but don't make the dashboard care which; have `AssetDashboardService` (or a small shared helper) expose one method that returns the right query params for a given card key, so the blade doesn't duplicate the computers-vs-laptops distinction.
- **Phones**: link to `route('assets.phones')` (whole page, no filter — it's already just the phones category). Each phone sub-badge (POS / SIM / Ph) links to `route('assets.phones', ['type' => 'POS'])` etc., reusing `PhonesList`'s existing `$assetType` via `#[Url(as: 'type')]`.
- **Network Devices**: link to `route('assets.network')`. Each sub-badge (RT / SW / AP / MiFi / P2P / 4GRT) links to `route('assets.network', ['type' => 'RT'])` etc., same pattern on `NetworkList`'s existing `$assetType`.
- Add `#[Url(as: 'type')]` to `$assetType` and `#[Url(as: 'category')]` (new property) on `AssetsList`, and `#[Url(as: 'type')]` to `$assetType` on `PhonesList`/`NetworkList`. Keep the existing `updating()` reset-page-on-filter-change logic working alongside the new bound properties.
- When arriving with a `category` or `type` filter from the dashboard, show a small dismissible "Filtered by: Computers ×" chip above the list (clear link resets the query param) — nice-to-have, skip only if it meaningfully complicates the component.

### 2. District breakdown row links

- `AssetsList` already has `$districtId` (`#[Url(as: 'district')]`) — wire the **District** cell (and/or the whole row) in `dashboard.blade.php`'s district table to `route('assets.assets', ['district' => $row['district_id']])`.
- Handle the **"Unassigned"** row (`district_id` is `null` in `AssetDashboardService::buildDistrictBreakdown()`) explicitly: today's `AssetsList::render()` filter is `where('district_id', (int) $this->districtId)` guarded by `!== ''`, which can't express "IS NULL". Add a sentinel (e.g. `district=unassigned` → `whereNull('district_id')`) so clicking the Unassigned row behaves correctly instead of either erroring or silently showing all districts.
- Scope note: a district's breakdown row shows per-category sub-counts (Computers/Laptops/Printers/Phones/Network), but clicking the row only takes you to the **Assets** list (which only ever shows computers/laptops/printers/servers) — Phones and Network for that district live on separate pages and won't be reflected there. That's what was asked for, so implement it as-is; optionally (skip if it adds too much surface) make the Phones/Network cells in each row separately clickable to `assets.phones`/`assets.network` with a `district` filter — which would require adding `$districtId` to those two components for the first time. Flag this as a follow-up rather than guessing whether it's wanted.

### 3. Tests

Extend `tests/Feature/Assets/*` (or add a new file) covering: the dashboard tiles render the right `href` for each category and sub-badge; a `category=computers` link to `assets.assets` returns only `PC`/`AIO` rows (and `printers` only `PRT`/`PTC`); a `type=Laptop`/`type=Server` link filters correctly; `assets.phones?type=POS` and `assets.network?type=RT` filter correctly; a `district=<id>` link to `assets.assets` scopes correctly, and `district=unassigned` returns only null-district rows; these all work as a direct GET request (bookmarkable), not only after a Livewire `wire:click`.

Run `php artisan test --filter=Assets` when done and summarize what you built, plus whether you added the Phones/Network per-row district links or left them as a flagged follow-up.
