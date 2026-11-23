# ICT Assets Module

## What this module adds

- `assets` module integrated into ERP navigation and dashboard.
- Asset inventory with:
  - search and filters (status, category, district). Super admin and the Head Office ICT team see every region (the district filter lists all districts, labelled with their region); a regional ICT user sees only their own region. Editing stays in the user's own region,
  - create/edit form,
  - "Last Seen" health indicator:
    - `🟢` seen in last 24 hours,
    - `🟡` seen in last 7 days,
    - `🔴` stale or never seen.
- Maintenance log workflow.
- Issue reporting workflow.
- Super-admin Agent Reports tab:
  - matched/unmatched filters,
  - unmatched row highlighting,
  - manual link from report to existing asset.

## Agent API endpoint

- `POST /api/agent/report`
- Auth: `Authorization: Bearer <api_token>`
- Payload fields:
  - `hostname` (required)
  - `serial_number`, `mac_address`, `os_name`, `os_version`, `cpu_name`, `ram_gb`,
    `logged_on_user`, `last_boot_time`, `manufacturer`, `model`, `bios_version`
- Matching order:
  - Serial number OR hostname OR MAC address.
- Result:
  - Always logs a row to `agent_reports`.
  - If matched, updates telemetry on `ict_assets`.
  - Returns:
    - `{ "status": "ok", "asset_id": <id|null>, "matched": true|false }`

## Token provisioning

Generate and assign an API token to a user:

```bash
php artisan tinker
```

```php
$user = App\Models\User::query()
    ->where('email', 'superadmin@ml.local') // replace with an existing super_admin or ict_team email
    ->first();

if (! $user) {
    throw new RuntimeException('User not found. Check email first.');
}

$token = bin2hex(random_bytes(30));
$user->update(['api_token' => $token]);
$token;
```

Use the returned token in the PowerShell script.

## Agent script

- Script path: `docs/agents/ict_agent.ps1`
- Example:

```powershell
powershell -ExecutionPolicy Bypass -File .\docs\agents\ict_agent.ps1 `
  -BaseUrl "https://your-erp-host" `
  -ApiToken "paste-token-here"
```

## Defender-safe deployment guidance

- Keep script source transparent (no obfuscation, no encoded payloads).
- Sign scripts with your org code-signing certificate.
- Distribute from trusted internal channels only (Intune, GPO, SCCM, signed package).
- Use allow-listing policies for signed scripts/publisher rules instead of disabling Defender.

## Upload (Assets, Phones, Network)

**Assets > Upload** (sidebar, and an "Upload ..." button on the Assets, Phones and Network lists) creates many records at
once from an Excel sheet. There is one tab per type; each has the same flow as the Staff import: download the template,
choose the file, **Preview**, then **Run Import**. It is create-only: it never changes or deletes a record.

| Tab | Route (old Assets URL kept) | Creates | Audit action |
|---|---|---|---|
| Assets | `assets.import` (`/assets/import`) | desktops, laptops, printers, servers... | `import_assets` |
| Phones | `assets.import.phones` | Phone, POS Terminal, SIM Card | `import_phones` |
| Network | `assets.import.network` | Router, Switch, Access Point, MiFi, P2P Radio, 4G Router | `import_network` |

**Who:** `assets.import` AND `assets.create` (super_admin always), the same for all three; there is no per-type
permission. The region is always the importer's own (a Location must be a district of it); there is no region column.

**Where the code is:** one service, `AssetImportService`, with a per-type profile (`AssetImportProfile`: columns, text
columns, staged-file folder, audit action, session key). `analyze()` is the only place a row is judged; preview, the issue
list and confirm all call it, and confirm re-reads the stored file (the session holds only its token, bound to the user
and to the type: the file sits in the folder of its type, so a Phones token opens nothing under Network). Rows are written
through `AssetRecordService::save(..., $audit = false)` and `AssetAssignmentService`, with ONE audit row of counts and a
batch id per import (never a name, serial, IMEI, holder or file name).

**The workbook:** `.xlsx` only, up to `GWL_ASSETS_IMPORT_MAX_MB` MB and `GWL_ASSETS_IMPORT_MAX_ROWS` rows. The template has
the sheet to fill in, an **Allowed values** sheet (types, statuses, manufacturers, models, the districts of your region,
and for Assets the departments) and an **Instructions** sheet. Only the FIRST sheet is read. Row 2 is an example whose
name starts `EXAMPLE - `: it is skipped (the preview says how many were ignored). Formulas (`=...`) are refused. Identifier
columns are text in the template; a number cell in a serial / IMEI / MAC / phone-number column is reported (a warning, or
an error when Excel has already rounded it).

### Template columns (required marked *)

- **Assets:** Asset Name*, Asset Type*, Manufacturer*, Model*, Serial Number*, Asset Tag, Department*, Location*, Actual
  Location, Status, Status Reason (when Damaged), Assigned To (Staff ID), General Use, Purchase Date, Warranty Expires.
- **Phones:** Asset Name*, Device Type*, Manufacturer*, Model*, Serial Number, IMEI, Status, Status Reason, Assigned To
  (Staff ID), General Use, Location, User Phone Number, Device Phone Number, Purchase Date. A Phone (type Ph) needs a
  serial number OR an IMEI (the phone form's rule, `AssetRecordService::phoneIdentityMissing`); a POS terminal or SIM card
  needs neither and may leave Manufacturer / Model blank. Serial and IMEI must not repeat in the file or the database.
- **Network:** Asset Name*, Device Type*, Manufacturer, Model (give both or neither), Serial Number, MAC Address, IP Address,
  SSID, Device Username, Location, Actual Location, Status, Status Reason. IP must be valid (and inside the location's
  ranges where there are any); the MAC needs 12 hex digits and is stored as `AA:BB:CC:DD:EE:FF`; serial and MAC must not
  repeat. Passwords are never imported.

### Rules shared by all three

Manufacturer, model and district must already exist (never created). The holder is found by staff ID among the people the
importer may pick in the form (a regional ICT user: their own region), must be active, and is never created. A blank
Location takes the holder's district when it is in the importer's region (a warning if not); a Location typed in the file
always wins and must be in the region. The preview lists every row's result, the first 200 issues, and a **Download issue
list** (row, column, severity, message, rebuilt from the stored file). "Import valid rows only" skips rows with errors;
without it nothing is imported while any row has an error. After a run the result card shows created / skipped / failed and
links back to the list.

## Racks (network cabinets / server racks)

**Assets > Racks** (`assets.racks`, `assets.racks.create|edit|show`, export `assets.racks.export.excel|pdf`). A rack is its
own record (`ict_racks`), **not** an asset type: devices point at it with `ict_assets.rack_id`, `rack_unit` (the starting
U, **1 = the bottom of the rack**) and `rack_unit_span` (the height in U, default 1). A device covers
`rack_unit .. rack_unit + rack_unit_span - 1`.

- **Which devices carry a position:** `IctAsset::RACK_ASSET_TYPES` = `Server`, `RT` (Router), `SW` (Switch), tested with
  `IctAsset::supportsRack($category, $type)` (the twin of `supportsUps()`). Access points, MiFi, P2P radios and 4G routers hang
  on walls and masts or sit on a desk, so they take no rack space; add a key to the constant to change that. The Rack /
  Starting U / U height fields are on the Assets form (servers) and the Network form (routers, switches) only, through the
  `HandlesRackPlacement` trait and the `x-assets.rack-fields` component. They are optional; once a rack is chosen the starting U
  and the height are required.
- **The rules live in `AssetRecordService`** (every writer goes through it, so they hold for the forms, the audit
  corrections and anything programmatic): `normaliseRack()` clears the three fields for a type with no rack or with no rack
  chosen (so changing a server to a laptop never leaves a stale position), and `guardRackPlacement()` runs inside the save
  transaction with the rack row locked: the range must fit in the rack's `total_units`, overlap no other device in it (the
  error names the device in the way), the rack must be in the device's own region and not Decommissioned. Only a placement that
  CHANGES is judged, so an unrelated edit of a device already mounted is never blocked by the rack's later state. The placement
  rule itself is `RackService::placementProblem()`.
- **Racks are written only by `RackService`** (create / update / delete, `assets.manage_racks`, re-read `lockForUpdate`, one
  audit row each: `create_rack`, `update_rack`, `delete_rack`). A rack's name is unique company-wide ignoring case, its code
  is optional and unique (a blank is NULL). It cannot be made shorter than the highest device in it, cannot be
  Decommissioned while devices are mounted, and **a rack with devices is never deleted** (the refusal says how many).
- **Scope:** a rack carries `region_id` (stamped from the actor's own region when created) and a `district_id` of that
  region, and is scoped exactly like an asset: reading is `scopeAssetsForViewing()` (Head Office ICT and unscoped roles see
  every region), writing is `scopeAssetsForActor()` / `RackService::mayWrite()` (own region; another region's rack answers 404).
  The device forms offer only racks of the actor's own region (plus the one the device is already in).
- **Permissions:** `assets.view_racks` (list, detail, the sidebar entry, the Rack row on a device page) and
  `assets.manage_racks` (create, edit, delete), granted to `super_admin`, `ict_team` and `ict_manager`; `admin` holds only MDM
  in Assets. Export of the rack list reuses `assets.export_inventory`. A person without `assets.view_racks` is not shown the
  device-form fields, and `rackPayload()` takes the keys out of what they save, so a forged request cannot change a placement.
- **List:** search (name, code, location, notes), status and district filters (all in the URL), selectable columns
  (`AssetListColumns::RACKS`, Phase 8) and the same Excel / PDF export as the other lists (one audit row per download).
  The detail page shows the rack, used / free U, the free stretches and the mounted devices from the bottom up.
- **Not built (separate follow-ups):** a visual rack-elevation diagram, power / load, cable / port mapping, rack analytics
  on Summary, a Racks card on the Dashboard, racks in the physical audit flow.
