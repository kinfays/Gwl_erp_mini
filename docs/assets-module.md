# ICT Assets Module

## What this module adds

- `assets` module integrated into ERP navigation and dashboard.
- Asset inventory with:
  - search and filters (status, category, district),
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
