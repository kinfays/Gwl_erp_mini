# UI Components and Design Tokens ("Deep Water")

The view layer is built from a small set of design tokens and shared Blade components under
`resources/views/components/ui/`. Use them for new screens instead of ad-hoc Tailwind colour
classes, so light and dark mode, focus states and spacing stay consistent.

## Tokens

Defined in `resources/css/app.css` (`@theme static` for light values, `.dark` for the dark swap).
Tailwind utilities exist for every colour token (`bg-surface`, `text-ink-2`, `border-line`…).

| Role | Token | Light | Dark | Use |
|---|---|---|---|---|
| Canvas | `--color-canvas` | `#eef2f6` | `#0a1120` | page background |
| Surface | `--color-surface` | `#ffffff` | `#111a2b` | cards, sidebar, top bar |
| Surface 2 / 3 | `--color-surface-2` / `-3` | `#f5f7fa` / `#e8edf3` | `#0d1524` / `#1a2539` | table head, hover, tracks |
| Line / strong line | `--color-line` / `--color-line-strong` | `#dde4ec` / `#7a899e` | `#223049` / `#5f6f88` | hairlines / input borders (≥ 3:1) |
| Ink 1 / 2 / 3 | `--color-ink`, `-ink-2`, `-ink-3` | `#0f1d31` / `#46566c` / `#5c6b80` | `#e8eef7` / `#b4c0d2` / `#95a3b8` | text (all ≥ 4.5:1 on every surface) |
| Primary (GWL Cobalt) | `--color-primary` (+ `-hover`, `-soft`, `-soft-ink`) | `#1447b9`, hover `#0039a3` | `#3566dc` | actions, current nav item, links |
| Lagoon | `--color-lagoon` (+ `-soft`, `-ink`) | `#0d7a74` | `#4cc3b8` | "live / on site", second data colour |
| Status | `--color-{success,warning,danger,info,muted}` (+ `-soft`, `-ink`) | see file | see file | status pills, alerts |
| Chart slots | `--color-series-1…8` | cobalt, orange, lagoon, amber, magenta, green, violet, red | re-stepped for dark | charts only, in this fixed order |

- **Type:** Atkinson Hyperlegible Next (UI) and Atkinson Hyperlegible Mono (identifiers only — staff IDs,
  plates, letter serials, checkout codes, IPs; use the `mono` class). 12px is the smallest size; body and
  tables are 14px. Extra steps: `text-meta` (13px) and `text-kpi` (28px).
- **Radius** follows containment: 4px checkbox → 8px controls (`rounded-control`) → 12px menus/chips
  (`rounded-menu`) → 16px cards (`rounded-card`) → 20px modals/drawers (`rounded-overlay`). Full pills only
  for status, badges, avatars and selection.
- **Elevation:** `--shadow-card` (resting) → `--shadow-raised` → `--shadow-overlay` (menus, toasts) →
  `--shadow-modal`. In dark mode elevation is a lighter surface step.
- **Motion:** 120–240ms, no entrance animations; `prefers-reduced-motion` turns transitions into fades.
- **Gradients:** only the area fill under a line chart.

## Components

All components pass extra attributes (`wire:model`, `wire:click`, `x-on:…`, `class`) through to the
element that owns them.

### page-header
`<x-ui.page-header title="All Employees" description="79 employees in scope">` — the page's single `<h1>`,
sitting under the layout's breadcrumb. Put actions in `<x-slot:actions>`, primary action last.

### card
`<x-ui.card title="Pending approvals" description="Oldest first">` with optional `<x-slot:actions>` and
`<x-slot:footer>`. `:padded="false"` lets a table or list run edge to edge. `heading="h3"` for nested cards.

### stat-tile
```blade
<x-ui.stat-tile label="Pending requests" :value="$pendingCount" icon="clock" tone="warning"
    meta="Oldest 5 days" delta="−4 vs last week" delta-tone="good" delta-direction="down" />
```
Tones: `primary · lagoon · success · warning · danger · info · muted`. `delta` and `sparkline` (array of
numbers) are optional and **must come from real data** — leave them out rather than inventing numbers.
Deltas are neutral unless the metric has a known good direction. Put tiles in `<div class="ui-stat-grid">`.

### status-pill
```blade
<x-ui.status-pill domain="leave" :status="$request->leave_status" />
```
| Domain | Mapping |
|---|---|
| `leave` | Planned → muted · Pending Approval → warning · Approved → success · Denied → danger |
| `recommendation` | Pending → warning · Recommended → info · Rejected → danger |
| `letter` | Received → info · In Review → warning · Dispatched → lagoon · Closed → muted |
| `checkout` (`visitors.checked_out_by`) | self → success "Self checkout" · receptionist → info "By reception" · auto → warning "Auto checkout" (the visitor never checked out) |
| `presence` | inside / on site → lagoon "On site" · out → muted "Checked out" |
| `account` | Active → success · Inactive / Deactivated → muted · On Leave → lagoon |
| `vehicle` | active → success · maintenance → warning · retired → muted |
| `issue` | open → warning · in_review / In Progress → info · in_maintenance → lagoon · resolved → success · closed → muted |
| `maintenance` (asset maintenance tickets) | Open → warning · In Progress → info · Completed → success · Cancelled → muted |
| `severity` | low → muted · medium → info · high → warning · critical → danger |
| `asset` | Active → success · In Repair → warning · Retired → muted · Lost → danger |
| `credit-union` | draft/completed/exited → muted · pending/variance → warning · approved/posted/paid → success · rejected/declined/defaulted → danger |

The pill always shows a text label; colour is never the only signal. Unknown values render as a neutral
pill with the stored value. `label` / `tone` override the mapping; `live` adds a halo for "happening now".

### table
```blade
<x-ui.card title="Employees" :padded="false">
    <x-ui.table label="Employees" pin-first>
        <x-slot:head><tr><th>Staff ID</th><th>Name</th><th class="num">Balance</th></tr></x-slot:head>
        @forelse ($employees as $employee)
            <tr>…</tr>
        @empty
            <x-ui.empty-row :colspan="3" icon="users" title="No employees match these filters">
                <x-ui.button size="sm" wire:click="resetFilters">Clear filters</x-ui.button>
            </x-ui.empty-row>
        @endforelse
        <x-slot:footer>{{ $employees->links() }}</x-slot:footer>
    </x-ui.table>
</x-ui.card>
```
The table scrolls inside its own keyboard-focusable region (never the page). `sticky` (default on) pins the
header, `striped`, `dense` and `pin-first` (first column stays visible when scrolling sideways) are
optional. Use `class="num"` on numeric cells (right-aligned, tabular figures) and `class="actions"` on the
last column. Pagination uses the app-styled views in `resources/views/vendor/{livewire,pagination}`.

### empty-state
`<x-ui.empty-state icon="inbox" title="No letters yet" description="…">` + a next action in the slot.
Always say what is missing and what to do next.

### button
`<x-ui.button variant="primary" icon="plus" wire:click="create" loading="create">New Role</x-ui.button>`
Variants `primary · secondary (default) · ghost · danger · danger-solid · warn`; sizes `sm · md · lg`;
`href` renders a link. One primary button per area. `loading="method"` disables the button and shows a
spinner while that Livewire action runs.

### Form controls
`<x-ui.input>`, `<x-ui.select>`, `<x-ui.textarea>` render a label, hint and the validation message for
their `wire:model` (or `name`) when given `label`; `required` only adds the visual marker.
`<x-ui.toggle label="…" wire:click="…">` is a `role="switch"` checkbox; `<x-ui.checkbox>` is a
full-row checkbox for lists; `<x-ui.field>` wraps any custom control.

### segmented
`<x-ui.segmented label="Range" wire:model.live="range" :options="['7d' => '7 days', '30d' => '30 days']" />`
Native radios, so arrow keys work. Only use it where a component already has the property.

### modal and drawer
- `<x-ui.modal title="Create New Role" close="$set('showCreateRole', false)">` inside the caller's
  `@if ($showCreateRole)`; footer buttons in `<x-slot:footer>`. Focus is trapped, Escape and the backdrop
  run `close`; phones get a bottom sheet.
- `<x-ui.drawer show="isOpen" close="close()" title="User Profile">` for Alpine-driven side panels.
- For "are you sure?" prompts keep using the global `confirm-action` event (`x-global.confirm-modal`).

### chart
Charts use **Chart.js 4** through one Alpine component (`resources/js/charts.js`) wrapped by
`<x-ui.chart>`. The Livewire view must load the bundle once at the top:
`@assets @vite('resources/js/charts.js') @endassets` (ChartAssetsTest checks that only chart pages do).

```blade
{{-- static data --}}
<x-ui.chart type="hbar" label="Leave usage by type" unit="%" :max="100"
    :labels="array_keys($leaveByType)" :series="[['label' => 'Usage', 'data' => array_values($leaveByType)]]" />

{{-- data a Livewire component re-sends in a browser event after a filter change --}}
<x-ui.chart type="area" label="Monthly leave requests"
    event="staff-leave-report-data-updated" source="monthlyLeaveRequests"
    :source-data="$payload['monthlyLeaveRequests'] ?? []" :series="[['label' => 'Requests', 'key' => 'data']]" />
```
- Types: `line · area · bar · hbar · doughnut`; options `unit` (`%`, `GHS`, `km`, `days`…), `min`/`max`,
  `stacked`, `legend` (`bottom · right · false`), `center` + `center-caption` (total inside a doughnut), `height`.
- Colours are read from the tokens at draw time and redrawn when the theme changes. Series take the fixed
  slot order; a series can set `color` (`series-3`, `success`…) or per-point `colors` / `colorsKey`, and
  `color-map` pins a colour to a category label (e.g. leave statuses) so it never changes with rank —
  labels missing from the map fall back to neutral.
- Data can also follow a Livewire property with `watch="propertyName"` (+ `source` path).
- Every chart has a **Table** toggle that shows the same numbers as text, the canvas has `role="img"` with
  the `label`, animation is off under `prefers-reduced-motion`, and an all-zero chart shows an empty message.
- Only chart numbers that come from a service. When there are just two or three numbers, use
  `<x-ui.split-bar>` (a labelled part-to-whole bar) instead of a chart.

### bar-list
Ranked magnitude bars in plain HTML for a handful of categories (no chart library, so no `@assets`):
```blade
<x-ui.bar-list label="Vehicles by department" empty="No vehicles found."
    :items="$rows->map(fn ($row) => ['label' => $row->department_name, 'value' => $row->total])" />
```
Each row shows the label, the number written out (`display` overrides the formatting, e.g. money) and a
single-hue bar scaled to the largest value. Use it for top-N lists on dashboards (Transport: fleet by
department, issues by status, highest spend); use `<x-ui.chart>` for time series or more than ~10 rows.

### meter
`<x-ui.meter label="Manager response" :value="31" :target="48" unit="h" />` — a value against a target
(SLA-style): the bar turns amber over target and red over twice the target, and the numbers are always
written out. Pass `:lower-is-better="false"` for targets you want to reach. A `null` value renders
"No data yet" (override with `empty-text`) instead of a misleading zero.

### alert, badge, avatar, icon
- `<x-ui.alert tone="warning" title="…">` — inline messages (`info · success · warning · danger`).
  An optional `<x-slot:actions>` sits at the end of the row, e.g. a dismiss
  `<button class="icon-btn alert-close" aria-label="Dismiss message">`.
- `<x-ui.badge tone="primary">12</x-ui.badge>` — counts and tags, not statuses.
- `<x-ui.avatar :name="$employee->full_name" />` — initials with a tint derived from the name.
- `<x-ui.icon name="bell" />` — Lucide line icons (ISC). Decorative by default; give icon-only buttons an
  `aria-label`. The available names are listed at the top of `components/ui/icon.blade.php`.

## App shell

`layouts/erp.blade.php` renders the top bar (module switcher from `ErpNavigation`, "Jump to a page"
(Ctrl/⌘ K), notification bells, theme toggle, account menu), the sidebar (identity block, grouped module
menu; a collapsible icon rail on desktop, a drawer below 1024px) and the breadcrumb. The theme lives in
`Alpine.store('theme')` (`resources/js/app.js`), saved as `gwl-theme` in localStorage;
`partials/theme-script.blade.php` applies it before first paint.

## Accessibility checklist for new screens

- One `<h1>` (use `page-header`), cards use `<h2>`.
- Every input has a visible label (`label` prop or `<x-ui.field>`).
- Status is text + colour; charts get a legend or direct labels and a table view.
- Icon-only buttons have an `aria-label`; don't remove focus outlines.
- Check both themes; tokens are contrast-checked, raw Tailwind colours are not.
