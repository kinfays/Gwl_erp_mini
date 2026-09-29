# Android Enterprise device management (MDM)

Company-owned, **fully managed** Android phones, managed through Google's Android Management API ("AMAPI") from the
ICT Assets module. A phone is enrolled by scanning a QR code at the setup wizard of a freshly factory-reset device. The
policy decides the exact app set, so nothing is cleaned up by hand first. There is no work profile and no personal side.

Everything here sits behind one switch, `GWL_MDM_ENABLED` (default **off**). With it off the MDM routes (including the
Google webhook) are not registered, the MDM sidebar entries are hidden, the `mdm:*` commands refuse to run and nothing is
scheduled.

- [What runs where](#what-runs-where)
- [Requirements: queue worker and scheduler](#requirements-queue-worker-and-scheduler)
- [One-time setup](#one-time-setup)
- [Settings reference](#settings-reference)
- [Event delivery: pull now, push in production](#event-delivery-pull-now-push-in-production)
- [Going live: pull → push checklist](#going-live-pull--push-checklist)
- [Policies](#policies)
- [Per-phone enrollment SOP](#per-phone-enrollment-sop)
- [Lost or stolen phone SOP](#lost-or-stolen-phone-sop)
- [Decommissioning and reassignment](#decommissioning-and-reassignment)
- [Device-model certification checklist](#device-model-certification-checklist)
- [Permissions and region scope](#permissions-and-region-scope)
- [Commands](#commands)
- [Troubleshooting](#troubleshooting)
- [AMAPI notes](#amapi-notes)

## What runs where

```
Policy (mdm_policies) ──publish──▶ Google
Enroll screen ──enrollmentTokens.create (additionalData = {asset_id, requested_by})──▶ QR code
Phone scans QR ─▶ Google ──Pub/Sub──▶ ERP (pull command OR push webhook)
                                        └▶ mdm_events (unique messageId = dedupe)
                                            └▶ ProcessAndroidNotification (queued job)
                                                ├ ENROLLMENT / STATUS_REPORT ─▶ mdm_devices (linked to the ict_assets phone)
                                                └ COMMAND ─▶ mdm_device_commands status
Action buttons ─▶ mdm_device_commands (requested) ─▶ SendMdmCommand (queued job) ─▶ Google
```

- **Device identity is not duplicated.** An `mdm_devices` row points at the phone's `ict_assets` record (serial, IMEI,
  model, assignee, region and district all come from the asset). The human action trail is the existing `audit_logs`
  (module `assets`).
- **Only phones of type "Phone" (`Ph`) can be enrolled**, not POS terminals or SIM cards. The phone must be Active, in
  the user's region, not already enrolled, and have a serial number or IMEI on its record (that is what the enrolling
  handset is verified against).
- **No screen calls Google for a command.** Livewire writes a row and dispatches a job. The two places that do call Google
  from a request are *Publish to Google* and *Generate QR code*, because the user is waiting for the answer.

## Requirements: queue worker and scheduler

MDM is the first feature in the ERP that **cannot work without a running queue worker**, and the first that needs the
scheduler **every minute** (the existing schedule entries are all daily).

| Needed | Why | Local (Laragon) | Production |
|---|---|---|---|
| Queue worker | Command delivery, event processing, "Sync now" | `composer run dev` already starts `queue:listen` | A managed service. **Not `composer run dev`.** |
| Scheduler, every minute | `mdm:poll-events` (pull mode), nightly `mdm:sync-devices`, `mdm:prune-events` | `php artisan schedule:work` | One entry that runs `php artisan schedule:run` every minute |

`QUEUE_CONNECTION` must be `database` (the default in `.env.example`) or another real driver. With `sync` a webhook
request would process the event inline instead of returning 204 immediately.

**Linux (Supervisor)** — `/etc/supervisor/conf.d/gwl-worker.conf`:

```ini
[program:gwl-worker]
command=php /var/www/erp_project/artisan queue:work --tries=3 --timeout=90 --sleep=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=1
stopwaitsecs=120
```

Cron for the scheduler: `* * * * * cd /var/www/erp_project && php artisan schedule:run >> /dev/null 2>&1`

**Windows** — either wrap `php artisan queue:work --tries=3 --timeout=90 --max-time=3600` with NSSM
(`nssm install GwlWorker`, restart on exit), or create Task Scheduler tasks that run at startup with "restart on failure".
The scheduler is a task triggered every minute running `php artisan schedule:run`.

Restart the worker after every deploy (`php artisan queue:restart`). The dashboard's **Event intake** tile shows
*Unprocessed* events; a number that keeps growing means the worker is not running.

## One-time setup

The Google Cloud project, the service account and the Android Management API enablement already exist.

### 1. Settings

Copy the block from `.env.example` into `.env` (see [Settings reference](#settings-reference)). At this point set:

```
GWL_MDM_ENABLED=true
GOOGLE_CLOUD_PROJECT_ID=<your project id>
GOOGLE_APPLICATION_CREDENTIALS=C:\secrets\gwl-amapi.json   # absolute path, OUTSIDE the web root and outside git
GWL_MDM_PUBSUB_TOPIC=projects/<project>/topics/gwl-amapi
GWL_MDM_PUBSUB_SUBSCRIPTION=projects/<project>/subscriptions/gwl-amapi-pull
GWL_MDM_PUBSUB_MODE=pull
```

Then `php artisan config:clear`. The credentials JSON is never logged and is never read by anything except the Google
client; do not paste it anywhere.

### 2. Pub/Sub topic and subscription

```bash
gcloud pubsub topics create gwl-amapi
# Google's Android Management service publishes device notifications to your topic:
gcloud pubsub topics add-iam-policy-binding gwl-amapi \
  --member="serviceAccount:android-cloud-policy@system.gserviceaccount.com" --role="roles/pubsub.publisher"

# Pull subscription for now:
gcloud pubsub subscriptions create gwl-amapi-pull --topic=gwl-amapi
# The ERP's service account reads it:
gcloud pubsub subscriptions add-iam-policy-binding gwl-amapi-pull \
  --member="serviceAccount:<your-service-account>@<project>.iam.gserviceaccount.com" --role="roles/pubsub.subscriber"
```

### 3. Bind the Android Enterprise

```bash
php artisan mdm:enterprise-signup
```

Open the printed URL in a browser, signed in as the Google account that will own the enterprise. When you finish, Google
redirects your browser to `<APP_URL>/assets/mdm/enterprise/callback?enterpriseToken=…`, a super-admin-only page in the
ERP with a **Create enterprise** button. (The redirect happens in *your browser*, so `https://erp_project.test` works;
Google never needs to reach the ERP for this step.) It prints the enterprise name.

Prefer the terminal? Copy the `enterpriseToken` from the redirect address and run
`php artisan mdm:enterprise-create <enterpriseToken>`.

Paste the result into `.env` and clear the config cache:

```
ANDROID_MANAGEMENT_ENTERPRISE_ID=enterprises/LC0xxxxxxx
```

The signup is remembered for 24 hours (the signup URL's name has to survive until the enterprise is created); after that,
run `mdm:enterprise-signup` again or pass `--signup-url-name`.

### 4. Turn notifications on

If `GWL_MDM_PUBSUB_TOPIC` was already set when you created the enterprise, this is done. Otherwise, or if you change the
topic later:

```bash
php artisan mdm:enterprise-notifications
```

This points the enterprise at the topic and enables `ENROLLMENT`, `STATUS_REPORT`, `COMMAND` and `USAGE_LOGS`. **Without
it Google publishes nothing and the ERP never hears about a phone.**

### 5. First policy

Open **ICT Assets → MDM Policies**. The seeded **GWL Standard Phone** policy has the locked-down settings and placeholder
force-installed apps (WhatsApp Business `com.whatsapp.w4b`, Chrome). Edit the app list, add at least one **factory reset
protection** account, then **Publish to Google** and read the diff. On an existing database the policy is created by the
`migrate`; to add it by hand run `php artisan db:seed --class=MdmStarterPolicySeeder`.

### 6. Check it end to end

`php artisan mdm:poll-events` should print `0 new, 0 already received, 0 skipped`. Then enroll one phone (below) and watch
the **Event intake** tile: *Last event received* moves and *Unprocessed* returns to 0.

## Settings reference

All live in `config/gwl.php` and are set in `.env`.

| Variable | Default | Purpose |
|---|---|---|
| `GWL_MDM_ENABLED` | `false` | Master switch. Off = no MDM routes/webhook/sidebar/schedule. |
| `GOOGLE_CLOUD_PROJECT_ID` | | Project that owns the API and the Pub/Sub topic. |
| `GOOGLE_APPLICATION_CREDENTIALS` | | **Absolute** path to the service-account JSON, outside the web root and git. |
| `ANDROID_MANAGEMENT_ENTERPRISE_ID` | | `enterprises/LC0…`, from `mdm:enterprise-create`. |
| `GWL_MDM_ENROLLMENT_TOKEN_MINUTES` | `60` | Lifetime of an enrollment QR (one-time use). |
| `GWL_MDM_LOST_MODE_MESSAGE` / `_PHONE` / `_ADDRESS` | message set | Defaults shown on a phone in Lost Mode (editable per phone). |
| `GWL_MDM_PUBSUB_TOPIC` | | `projects/<p>/topics/<t>`. |
| `GWL_MDM_PUBSUB_SUBSCRIPTION` | | `projects/<p>/subscriptions/<s>`, the **pull** subscription used by `mdm:poll-events`. |
| `GWL_MDM_PUBSUB_MODE` | `pull` | `pull` or `push`. Anything else behaves as `pull`. |
| `GWL_MDM_PUBSUB_PUSH_AUDIENCE` | | Push only: the OIDC audience configured on the push subscription. |
| `GWL_MDM_PUBSUB_PUSH_SERVICE_ACCOUNT` | | Push only: email of the dedicated push service account. |
| `GWL_MDM_PUBSUB_PUSH_TOKEN` | | Push only: long random secret appended to the endpoint as `?token=`. |
| `GWL_MDM_PUBSUB_PULL_BATCH` | `50` | Messages per pull request. |
| `GWL_MDM_WEBHOOK_RATE_PER_MINUTE` | `600` | Webhook rate limit per source IP. |
| `GWL_MDM_COMMAND_RATE_PER_MINUTE` | `6` | Commands one user may queue per minute. |
| `GWL_MDM_COMMAND_VALID_MINUTES` | `60` | How long an undelivered command stays valid at Google. |
| `GWL_MDM_STALE_REPORT_HOURS` | `24` | "Not reported recently" threshold. |
| `GWL_MDM_EVENT_RETENTION_DAYS` | `90` | `mdm:prune-events` keeps processed events this long. |

Generate the push token with something like `php -r "echo bin2hex(random_bytes(32));"`.

## Event delivery: pull now, push in production

Both modes hand each message to the **same** service and the **same** `ProcessAndroidNotification` job, and both dedupe on
Pub/Sub's `messageId` (a unique column on `mdm_events`). Switching modes, or running both for a while, can never process
an event twice: whichever delivery arrives first is stored and queued, and any repeat is recognised, acknowledged (pull) or
answered `204` (push), and ignored.

**Pull (Laragon now, and the fallback).** `mdm:poll-events` is scheduled every minute with `withoutOverlapping()`. It runs
only when `GWL_MDM_PUBSUB_MODE=pull`, or manually with `--force`. A message is acknowledged **only after** its `mdm_events`
row is stored and its job dispatched; anything that fails before that is left unacknowledged and Google redelivers it. An
undecodable message is dropped and acknowledged so it is not redelivered forever.

**Push (production).** Google POSTs to `/webhooks/android-management?token=<secret>`:

- No session, no CSRF check, no login: the route drops the `web` middleware group and is rate limited. It is registered
  only when `GWL_MDM_ENABLED=true`.
- Authenticated by **Google's OIDC token** (`Authorization: Bearer <jwt>`), not the `api_token` check the agent endpoints
  use. Signature against Google's published keys (cached, refetched only when Google rotates a key); issuer
  `accounts.google.com`; audience = `GWL_MDM_PUBSUB_PUSH_AUDIENCE`; email = `GWL_MDM_PUBSUB_PUSH_SERVICE_ACCOUNT` and
  `email_verified`; not expired. **Plus** the secret `?token=` (`hash_equals`). If any of the three settings is empty
  every request is rejected.
- Rejections are `401` and are logged with a short reason and the caller IP only, never the payload, JWT or token.
- It stores the event, dispatches the job and returns `204` in milliseconds. It never calls Google. Duplicates also return
  `204`; only a genuinely malformed body returns `400`.

**Safety net (both modes):** `mdm:sync-devices` runs nightly at 02:15, lists every device at Google, reconciles
`mdm_devices` (creating anything missed, marking vanished devices deleted) and re-checks commands that were sent but never
reported back. A Google list that comes back empty never mass-deletes local devices.

## Going live: pull → push checklist

1. **Confirm the public URL serves valid HTTPS.** `AppServiceProvider` already forces the `https` scheme; check
   `https://<domain>/up` from outside.
2. **Create a dedicated push service account** (no other roles):
   `gcloud iam service-accounts create gwl-pubsub-push`. Its email is `GWL_MDM_PUBSUB_PUSH_SERVICE_ACCOUNT`.
3. **Choose the audience** (any string, conventionally the endpoint URL without the query) →
   `GWL_MDM_PUBSUB_PUSH_AUDIENCE`, and generate the secret → `GWL_MDM_PUBSUB_PUSH_TOKEN`.
4. **Edit the subscription to push**, endpoint `https://<domain>/webhooks/android-management?token=<secret>`, OIDC
   authentication with the push service account and the audience:
   ```bash
   gcloud pubsub subscriptions modify-push-config gwl-amapi-pull \
     --push-endpoint="https://<domain>/webhooks/android-management?token=<secret>" \
     --push-auth-service-account="gwl-pubsub-push@<project>.iam.gserviceaccount.com" \
     --push-auth-token-audience="<audience>"
   ```
5. **If the Google project predates April 2021**, grant the Pub/Sub service agent
   `roles/iam.serviceAccountTokenCreator` on the push service account:
   ```bash
   gcloud iam service-accounts add-iam-policy-binding gwl-pubsub-push@<project>.iam.gserviceaccount.com \
     --member="serviceAccount:service-<PROJECT_NUMBER>@gcp-sa-pubsub.iam.gserviceaccount.com" \
     --role="roles/iam.serviceAccountTokenCreator"
   ```
6. **Add a dead-letter topic** with a maximum delivery attempts (for example 10) so a poison message stops retrying:
   ```bash
   gcloud pubsub topics create gwl-amapi-dead
   gcloud pubsub subscriptions update gwl-amapi-pull --dead-letter-topic=gwl-amapi-dead --max-delivery-attempts=10
   ```
   Grant the Pub/Sub service agent `roles/pubsub.publisher` on the dead-letter topic and `roles/pubsub.subscriber` on the
   subscription (Google's console offers to do this for you).
7. **Set `GWL_MDM_PUBSUB_MODE=push`** and `php artisan config:clear` (and `queue:restart`).
8. **Confirm events arrive.** Lock a test phone or enroll one, then check `mdm_events`: new rows with
   `delivery_mode = 'push'` and `processed_at` set. The dashboard's **Event intake** tile should show `PUSH`, a recent
   *Last event received*, and 0 failed / 0 unprocessed.
9. **Keep the pull command available as the fallback.** A *push* subscription cannot be pulled, so either flip the
   subscription back (`--push-endpoint=""`, mode `pull`) or keep a second pull subscription on the topic
   (`--message-retention-duration=1h`) and point `GWL_MDM_PUBSUB_SUBSCRIPTION` at it. Then `php artisan mdm:poll-events --force`
   drains it; overlap with push is harmless because of the `messageId` dedupe.

Rolling back is the same in reverse: `GWL_MDM_PUBSUB_MODE=pull` plus `modify-push-config --push-endpoint=""`.

## Policies

**ICT Assets → MDM Policies.** A policy is edited and saved locally first; nothing reaches Google until **Publish to
Google**, which saves, validates, and shows a diff of exactly what would change against the version Google last accepted,
with non-blocking warnings, before you confirm. Publishing writes an audit entry with the old and new payload.

| Setting in the editor | AMAPI field |
|---|---|
| App catalog (package, install type, permissions) | `applications[].packageName / installType / defaultPermissionPolicy` |
| Play Store mode | `playStoreMode` (`WHITELIST`: anything not in the policy is removed) |
| Block install / uninstall | `installAppsDisabled`, `uninstallAppsDisabled` |
| Disable factory reset | `factoryResetDisabled` |
| **Factory reset protection accounts** | `frpAdminEmails` |
| Block other users, screenshots | `addUserDisabled`, `screenCaptureDisabled` |
| Camera | `cameraAccess` |
| USB data | `deviceConnectivityManagement.usbDataAccess` |
| Apps from unknown sources | `advancedSecurityOverrides.untrustedAppsPolicy` (`DISALLOW_INSTALL`) |
| Developer settings | `advancedSecurityOverrides.developerSettings` (`DEVELOPER_SETTINGS_DISABLED`) |
| Passcode quality / length | `passwordPolicies[]` (scope `SCOPE_DEVICE`) |
| System updates | `systemUpdate` |
| *(always on)* status reporting | `statusReportingSettings`: application reports, device settings, software info, hardware status, **network info** (the IMEI lives there) |

- **A policy with no enabled `FORCE_INSTALLED` app is refused**: the phone would finish setup with nothing installed.
- Published with an `updateMask` covering every field the ERP owns, so removing a setting locally (an emptied FRP list, no
  passcode rule) resets it at Google instead of silently keeping the old value.
- Deliberately never set: `leaveAllSystemAppsEnabled` (fully managed provisioning must disable non-essential system apps;
  it is a provisioning extra Google's QR payload leaves off) and any wipe-after-failed-passcode setting (the ERP never
  auto-wipes).
- `installType` values offered: `FORCE_INSTALLED`, `REQUIRED_FOR_SETUP` (blocks setup completion until installed),
  `AVAILABLE`, `BLOCKED`.
- **Factory reset protection is the main anti-theft control.** With `frpAdminEmails` empty, a wiped stolen phone can be set
  up again by anyone. The publish screen warns until at least one account is set. Use real Google accounts that the
  company controls and can sign in to.

## Per-phone enrollment SOP

**ICT Assets → Enroll Phone** (needs `assets.mdm_enroll`). Choose the phone (only eligible phones in your region are
listed) and a published policy, then **Generate QR code**. The code is one-time-use and valid for 60 minutes; it is shown
once and never stored, so if it expires or the screen is closed, generate a new one.

1. **Factory reset the phone.** Settings → System → Reset options → Erase all data. No manual app cleanup is needed.
2. **At the "Welcome" screen tap the screen 6 times** to open the QR scanner, and connect to Wi-Fi when asked.
3. **Scan the QR code.** The phone downloads the management app and enrolls as a company-owned, fully managed device.
4. **Wait for the policy to finish installing apps** before handing the phone over.

Within a minute or two the phone appears under **MDM Devices** as *Compliant*.

**What "Needs review" means.** The device is linked to the asset the QR was generated for, then checked: its serial number
and IMEI (as reported by the phone) must agree with the asset record. If either disagrees, or the phone reported nothing to
compare, or it enrolled without an ERP-issued token, the device is still recorded but flagged, and **lock, reboot and Lost
Mode are disabled** until an administrator opens the device and clicks **Confirm identity** (audited). Wipe stays available,
because it targets the physical phone. Unlinked devices are visible to admins only. Typical causes: the wrong phone was
scanned, or the serial/IMEI on the asset record has a typo, so fix the record, then confirm.

## Lost or stolen phone SOP

1. **Lost Mode first** (device page → *Start Lost Mode*, or set the phone's status to *Lost* on the Phones screen and accept
   the offer). Confirm the asset tag, serial, IMEI and assignee in the dialog, check the message and call-back number. The
   phone locks and shows them. Nothing is sent until you confirm.
2. If the phone is found: *Stop Lost Mode* (or set its status back and accept the offer).
3. **Wipe only after 24 hours or more unrecovered, and with approval.** Wipe needs `assets.mdm_wipe` (admin and
   super_admin only), the acknowledgement tick and **your own password**. Leave **Keep factory reset protection** ticked so
   the wiped phone still needs a company account. The ERP never wipes anything automatically. Note that a wipe reaches the
   phone only when it is online: a phone that stays switched off or offline is not erased until it reconnects, which is
   exactly why Lost Mode comes first.

Every command, and its outcome, is in the device's command history and in the audit log.

## Decommissioning and reassignment

Retiring a phone, or moving it to someone else, follows the same order:

1. **Remove it from management**: use *Wipe phone* on the device page (this is `devices.delete`: it removes the device from
   the enterprise and factory-resets it). Untick *Keep factory reset protection* only if you will not be able to sign in
   with a company account at setup.
2. **Factory reset** the phone if the wipe could not reach it (it was offline).
3. **Re-enroll** if it is going back into service: update the asset record (assignee, location, status), then follow the
   enrollment SOP. The old device row is reused, so history stays attached to the asset.

If the phone still shows in Google's console after retiring, delete it there.

## Device-model certification checklist

Test **each phone model we buy** on a physical unit before rolling it out:

- [ ] QR enrollment completes from the 6-tap Welcome screen on the model's Android version.
- [ ] The device reports `DEVICE_OWNER` (fully managed) and the policy version applies.
- [ ] Every `FORCE_INSTALLED` app installs; the allow-list removes everything else; users cannot install or uninstall.
- [ ] Compliance shows *Compliant* with no unexpected `nonComplianceDetails` (an unsupported setting such as
      `DISALLOW_USB_DATA_TRANSFER` on Android < 12 shows here).
- [ ] The phone reports its **serial number and IMEI**, and they match the asset record (otherwise it lands in *Needs review*).
- [ ] *Lock* works; *Reboot* works; *Reset passcode* works.
- [ ] *Start Lost Mode* shows the message and phone number and blocks use; the device reports state `LOST`; *Stop Lost Mode*
      restores it. (Confirms the ERP's lost flag matches what the device reports.)
- [ ] *Wipe* (a spare unit): the phone factory-resets, and with FRP kept it asks for a company account at setup.
- [ ] Factory reset from Settings is blocked, developer options are blocked, side-loading is blocked.
- [ ] Status reports arrive without prompting (app list, patch level, last check-in).
- [ ] Battery/data impact of status reporting is acceptable over a few days.

## Permissions and region scope

| Permission | Lets you | ict_team | admin | super_admin |
|---|---|:-:|:-:|:-:|
| `assets.mdm_view` | See the dashboard, devices and policies | ✅ | ✅ | ✅ |
| `assets.mdm_enroll` | Generate enrollment QR codes | ✅ | ✅ | ✅ |
| `assets.mdm_command` | Lock, reboot, reset passcode, Lost Mode; confirm a device's identity (unscoped roles only) | ✅ | ✅ | ✅ |
| `assets.mdm_manage_policies` | Create, edit and publish policies | | ✅ | ✅ |
| `assets.mdm_wipe` | Wipe (also needs your password) | | ✅ | ✅ |

- **Region scope.** ICT users who are not admin or super_admin can only see, enroll and command phones whose *asset* is in
  the region of their own staff record (no staff record or region = nothing). Admin and super_admin see every region,
  plus unlinked devices. This is enforced in every query and every action, and again inside the queued job when a command is
  delivered (so a permission or region change while a command is queued still applies). Device and asset ids from the
  browser are `#[Locked]` where held in properties and always re-resolved through `MdmAccessGuard`.
- **`admin` and the rest of Assets.** `admin` can use the MDM screens but nothing else in Assets: it has Assets module
  access for MDM only, its **ICT Assets** tile lands on the MDM dashboard, and the inventory, maintenance and reporting
  routes still answer 403 for it. (The MDM routes are their own route group for exactly this reason.)
- Grant or revoke any of these per role in **Access Control → Roles & Permissions**.

## Commands

| Command | Purpose |
|---|---|
| `mdm:enterprise-signup` | One-time: print the Google signup URL. |
| `mdm:enterprise-create {token}` | One-time: create the enterprise, print `ANDROID_MANAGEMENT_ENTERPRISE_ID`. |
| `mdm:enterprise-notifications` | Point the enterprise at the Pub/Sub topic and enable notification types. |
| `mdm:poll-events [--force]` | Pull mode delivery (scheduled every minute). No-op in push mode without `--force`. |
| `mdm:sync-devices` | Full resync (scheduled nightly, 02:15). |
| `mdm:prune-events [--days=N]` | Delete processed events past retention (scheduled nightly, 03:00). |

All `mdm:*` commands except `mdm:prune-events` refuse to run while `GWL_MDM_ENABLED=false`.

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing appears after enrolling | `mdm:enterprise-notifications` was run; the topic has the Publisher role for `android-cloud-policy@system.gserviceaccount.com`; in pull mode the scheduler is running; `php artisan mdm:poll-events` prints new messages. |
| *Unprocessed* keeps growing | The queue worker is not running or is stuck; `failed_jobs`; `queue:restart`. |
| *Failed events* > 0 | `mdm_events.error` holds the message; fix the cause, then clear `error` (the job is safe to re-run). |
| Push returns 401 | The log line carries the reason: `bad_query_token`, `bad_audience`, `bad_email`, `expired`, `not_configured`… Compare the subscription's audience and service account with `.env`. |
| Push returns 400 | The body is not a Pub/Sub message; only Google should be calling the endpoint. |
| Publish says "ANDROID_MANAGEMENT_ENTERPRISE_ID is not set" | Enterprise not bound yet, or `config:clear` missing. |
| QR will not scan | Enlarge the browser window; hold the phone steady 20–30 cm away; generate a fresh code (they expire). |
| Device stays *Non-compliant* | Open the device: the reasons are listed (an app that failed to install, an unsupported setting on that Android version). |
| "Sync now" does nothing | It is a queued job; the worker must be running. |

## AMAPI notes

Field names in this integration were verified against Google's current API discovery document, not memory. Where the API
differs from what one might assume:

- **`WIPE` is now also an `issueCommand` type** (with `wipeParams`), but the ERP deliberately uses **`devices.delete`** for
  wipe: Google documents it as removing the device from the enterprise (it stops appearing in device lists) while
  attempting the factory reset, which is what decommissioning wants. Both are valid; the `WIPE` command is the alternative
  if that behaviour is ever unwanted, and it is executed when the device acknowledges it. Google notes that a delete
  cannot guarantee the wipe if the phone stays offline for an extended period.
- `usbDataAccess` lives under **`deviceConnectivityManagement`**, with values `ALLOW_USB_DATA_TRANSFER`,
  `DISALLOW_USB_FILE_TRANSFER`, `DISALLOW_USB_DATA_TRANSFER` (Android 12+).
- `passwordRequirements` is deprecated; the ERP writes **`passwordPolicies[]`**.
- The status-reporting flags are named `applicationReportsEnabled`, `deviceSettingsEnabled`, `softwareInfoEnabled`,
  `hardwareStatusEnabled`; the IMEI is only reported when **`networkInfoEnabled`** is on, which the brief did not list but
  the identity check needs.
- `cameraAccess` values are `CAMERA_ACCESS_USER_CHOICE`, `CAMERA_ACCESS_DISABLED`, `CAMERA_ACCESS_ENFORCED`
  (`cameraDisabled` is deprecated).
- `leaveAllSystemAppsEnabled` is not a Policy field (it is a provisioning extra); the ERP uses Google's `qrCode` payload
  as-is and never sets it.
- `devices.delete` accepts `wipeDataFlags`: `PRESERVE_RESET_PROTECTION_DATA` (the ERP's default), `WIPE_EXTERNAL_STORAGE`,
  `WIPE_ESIMS`. Google describes the first as "preserve the factory reset protection data on the device", so a wipe sent
  without it does not.
- `enterprises.create` needs the `signupUrlName` from `signupUrls.create`, so the ERP keeps it for 24 hours.
- Enabling notifications is a separate step (`enabledNotificationTypes` + `pubsubTopic` on the enterprise) that the ERP
  performs through `mdm:enterprise-notifications`.
- A device's `state` `LOST` and the ERP's lost flag: the ERP sets its flag when a Start Lost Mode command is acknowledged
  or a report says `LOST`, and clears it only when a Stop Lost Mode command is acknowledged.
