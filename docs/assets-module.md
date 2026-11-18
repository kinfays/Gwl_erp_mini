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
