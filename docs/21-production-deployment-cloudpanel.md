# Production deployment on Ubuntu + CloudPanel

Last verified against the code: 2026-11-16 (Laravel 13.33, Livewire 4.4, PHP `^8.4.1` with production on 8.5, Node 22+).

This guide takes a junior sysadmin from an empty Ubuntu server to a running ERP: web site, database, queue worker, scheduler,
integrations, security, backups and the update procedure. Every command says **who runs it**. Anything that could not be confirmed from
the repository is marked **VERIFY**; the list of those is collected in the "VERIFY list" near the end, and real gaps in the repository
are in "Problems found".

**Placeholders used everywhere** (replace them once, in your notes, before you start):

| Placeholder | Meaning | Example |
|---|---|---|
| `<DOMAIN>` | public host name of the ERP | `erp.example.com.gh` |
| `<SITE_USER>` | the CloudPanel site user that owns the app | `erp` |
| `<APP_DIR>` | the application folder | `/home/<SITE_USER>/htdocs/<DOMAIN>` |
| `<PHP>` | the PHP binary used by the site | `/usr/bin/php8.5` (VERIFY with `ls /usr/bin/php*`) |
| `<DB>`, `<DB_USER>` | database name and user | `erp_prod`, `erp_app` |

> Golden rules: (1) never run `php artisan ...` as `root` (it creates root-owned files that PHP-FPM cannot write); (2) never run
> `php artisan migrate:fresh`, `db:wipe`, `cache:clear` or `optimize:clear` on production; (3) never run any `*:demo-data` or `*:sample`
> command or `DemoDataSeeder` on production; (4) the `.env` file and `APP_KEY` are the keys to the building, back them up and never
> commit them.

---

## 0. Overview

### 0.1 Architecture

```
                        Internet
                           |
        :443 (HTTPS, Let's Encrypt)      :8443 (CloudPanel admin UI, restrict to admin IPs)
                           |
                +----------v-----------+
                |  Nginx (CloudPanel)  |  vhost: root = <APP_DIR>/public
                |  client_max_body_size|  static files (/build/*) served directly
                +----------+-----------+
                           | FastCGI (unix socket)
                +----------v-----------+        +-----------------------------+
                |  PHP-FPM pool        |        |  Supervisor                 |
                |  runs as <SITE_USER> |        |  2 x "php artisan queue:work"|
                |  Laravel 13/Livewire |        |  runs as <SITE_USER>        |
                +----+-----------+-----+        +--------------+--------------+
                     |           |                             |
                     |           +---------- same code, same .env ------+
                     |                                         |
          +----------v-----------+              +------------v-------------+
          |  MySQL 8.4 (127.0.0.1)|<------------+  cron (every minute)      |
          |  data, jobs, sessions |              |  php artisan schedule:run |
          |  cache, locks         |              |  runs as <SITE_USER>      |
          +-----------------------+              +---------------------------+

   Disk: <APP_DIR>/storage/app/private  (uploads, never public)      Outbound HTTPS only:
         <APP_DIR>/storage/app/public   (2 public features, see 8.3)   Google APIs, Arkesel, SMTP

   Inbound from the Internet that is NOT a person: POST /webhooks/android-management (Google Pub/Sub),
                                                   POST /webhooks/sms-arkesel (optional)
```

### 0.2 Every required background process and credential

| # | What | Needed when | Who runs it | Where it is set up |
|---|---|---|---|---|
| 1 | Nginx + PHP-FPM | always | CloudPanel | section 2 |
| 2 | MySQL 8.4 (MariaDB works, see 4.1) | always | CloudPanel | section 4 |
| 3 | **Queue worker** (`queue:work`, 2 processes) | always (notifications; **required** by Commercial customer list, Detailed Billing, SMS, MDM) | Supervisor, as `<SITE_USER>` | section 9 |
| 4 | **Scheduler** (`schedule:run` every minute) | always (visitor auto-checkout, leave jobs, transport expiries; more with each module flag) | cron, as `<SITE_USER>` | section 10 |
| 5 | Redis | optional (section 16) | systemd | section 16 |
| 6 | Outbound mail (SMTP) | always (leave emails, invites, password reset) | SMTP provider | section 6 |

| Credential / secret | Env key or place | Secret? | Notes |
|---|---|---|---|
| Application key | `APP_KEY` | **YES** | Also encrypts leave signatures and MDM command payloads. **Lose it and those become unreadable.** Back it up separately. |
| Database password | `DB_PASSWORD` | **YES** | |
| SMTP password | `MAIL_PASSWORD` | **YES** | |
| Super admin password | set in 7.3 | **YES** | The seeder ships a known password; change it first. |
| GitHub deploy key (private half) | `~/.ssh/erp_deploy` | **YES** | read-only deploy key |
| Google service-account file | file named by `GOOGLE_APPLICATION_CREDENTIALS` | **YES** | MDM only; outside the web root, mode 600 |
| Pub/Sub push secret | `GWL_MDM_PUBSUB_PUSH_TOKEN` | **YES** | MDM push mode only |
| Arkesel API key | `GWL_ARKESEL_API_KEY` | **YES** | SMS only |
| Arkesel webhook secret | `GWL_ARKESEL_WEBHOOK_TOKEN` | **YES** | SMS only |
| Redis password | `REDIS_PASSWORD` | **YES** | only if Redis is used |
| CloudPanel admin login + MFA | CloudPanel | **YES** | |
| Backup storage credentials | CloudPanel / rclone | **YES** | |
| Project ID, enterprise ID, topic, subscription, audience, service-account e-mail | `GOOGLE_CLOUD_PROJECT_ID`, `ANDROID_MANAGEMENT_ENTERPRISE_ID`, `GWL_MDM_PUBSUB_*` | no (but do not publish) | |

Keep all secrets in the organisation's password manager. Never paste them into tickets, chats or this repository.

---

## 1. Prerequisites and sizing

### 1.1 Server

* **Fresh** Ubuntu **24.04 LTS** (22.04 LTS also works) with nothing else installed. CloudPanel must be installed on a clean server (VERIFY the supported list on the CloudPanel installation page for the CloudPanel version you download).
* Public IPv4, root SSH access, and a DNS name you control.
* Do not host other sites on this server: the Commercial imports run for minutes to hours and use a lot of CPU, memory and disk I/O.

### 1.2 Sizing

The heavy parts are the **Commercial Customer List** (millions of rows; the benchmark in `docs/15-module-commercial-customer-list.md` measured a table of several GB with 9 indexes at 5 million customers), the **Detailed Billing Report** (a 133 MB sheet per region and period) and the **queue workers**.

| Profile | vCPU | RAM | Disk (NVMe/SSD) | When |
|---|---|---|---|---|
| Minimum (pilot, other modules, Commercial without the customer list) | 4 | 8 GB | 160 GB | up to ~1 million customers or no customer list |
| **Recommended** | 8 | 32 GB | 500 GB | 5 to 10 million customers |

How the RAM is shared on the recommended server: MySQL buffer pool 16 GB, PHP-FPM about 8 GB, two queue workers up to 1 GB each, OS and page cache the rest. Disk: the database (several times the raw file size once indexed), `binlog` files, uploads under `storage/app/private`, and **local backup copies** (section 13). Add a 4 GB swap file as a safety net.

> **VERIFY**: the 5-million-customer benchmark missed its targets on a laptop with a 128 MB buffer pool. Before relying on these sizes for 5 to 10 million customers, re-run `php artisan commercial:customers:benchmark` on the real server settings (it refuses any database whose name does not contain `bench`) and run `ANALYZE TABLE commercial_customers` after the first big load, as the Customer List doc says.

### 1.3 Domain and DNS

1. Pick the host name, for example `erp.<your-domain>`.
2. At your DNS provider create an **A record** `erp` -> the server's public IPv4 (and an AAAA record only if the server really has working IPv6). Lower the TTL to 300 s.
3. Wait until `dig +short <DOMAIN>` returns the server IP: Let's Encrypt (section 2) fails otherwise.
4. If the organisation uses Cloudflare or another proxy, set the record to **DNS only (grey cloud)** until the end of this guide; proxying changes the client IP and the scheme the app sees (Problems found, item 4).

### 1.4 Install CloudPanel (run as `root`)

```bash
apt update && apt -y upgrade
apt -y install curl wget ca-certificates
timedatectl set-timezone Africa/Accra     # UTC+0 all year; the app itself is fixed to UTC (config/app.php)
reboot
```

After the reboot, install CloudPanel **exactly as the current official page shows** (it prints the installer command and its SHA-256 check; copy it from the page, do not retype a hash from this guide):

```bash
# https://www.cloudpanel.io/docs/v2/getting-started/other/   (VERIFY: current command and checksum)
curl -sS https://installer.cloudpanel.io/ce/v2/install.sh -o /root/install.sh
echo "<SHA256 FROM THE OFFICIAL PAGE>  /root/install.sh" | sha256sum -c
DB_ENGINE=MYSQL_8.4 bash /root/install.sh     # VERIFY the exact DB_ENGINE value the installer accepts
```

Good result: the installer ends by printing `https://<server-ip>:8443`. **Open it immediately and create the admin user**: whoever opens that page first becomes the administrator.

### 1.5 Phase 1 checklist

- [ ] Ubuntu LTS server with the sizing above, root SSH works.
- [ ] `<DOMAIN>` resolves to the server IP (`dig +short <DOMAIN>`).
- [ ] CloudPanel installed, admin user created, MySQL 8.4 chosen.
- [ ] 4 GB swap file created (`fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile`, add to `/etc/fstab`).

---

## 2. CloudPanel site, HTTPS and trusted proxies

### 2.1 Create the site (CloudPanel UI)

1. Log in at `https://<server-ip>:8443`.
2. **Sites** -> **Add Site** -> **Create a PHP Site**.
3. **Application**: choose **Laravel** (the template points the web root at `public`). VERIFY the template name for Laravel 13; if there is none, choose the newest Laravel template or *Generic* and set the root directory in step 6.
4. **Domain Name**: `<DOMAIN>`.
5. **PHP Version**: **8.5** if offered (production runs 8.5, `composer.json` needs `^8.4.1`, and `config.platform.php` is pinned to `8.5.0`). If your CloudPanel only offers 8.4, 8.4 works (no locked package needs 8.5) but note it in your runbook. VERIFY.
6. **Site User** `<SITE_USER>` and a strong **Site User Password**. Create the site.
7. Open the site -> **Vhost** and confirm the line `root /home/<SITE_USER>/htdocs/<DOMAIN>/public;`. If it points at the folder itself, change it to end in `/public`.
8. Leave **Varnish Cache** switched **off** for this site (an ERP with sessions must not be cached, and Varnish would also hide the real client IP and scheme).

### 2.2 Let's Encrypt certificate (CloudPanel UI)

1. Site -> **SSL/TLS** -> **Actions** -> **New Let's Encrypt Certificate**.
2. Tick the domain, **Create and Install**. Renewal is automatic.
3. Check from your laptop:

```bash
curl -sI http://<DOMAIN>  | head -3     # expect a 301 to https:// (VERIFY; if not, add the redirect in the vhost)
curl -sI https://<DOMAIN> | head -3     # expect HTTP/2 200 or 302
```

### 2.3 Force HTTPS and trusted proxies (what the code does)

* `AppServiceProvider::boot()` calls `URL::forceScheme('https')`, so every generated link and asset URL is `https://...` whatever the server thinks.
* `bootstrap/app.php` configures **no trusted proxies**. That is correct for this layout: Nginx talks to PHP-FPM over FastCGI and passes `HTTPS=on` itself, so Laravel sees the real scheme and `$request->ip()` is the visitor's IP.
* It is **not** correct if you later put a proxy in front (Cloudflare orange cloud, a load balancer, Varnish): the app would then see the proxy's IP, and the login lock-out and the webhook rate limits (both keyed on `$request->ip()`) would treat everyone as one visitor. Do not add a proxy without a code change (Problems found, item 4).
* `APP_URL` must be the exact public address `https://<DOMAIN>` (no trailing slash). It is used for the public disk URL, mail links and as the fallback for the Health & Safety QR base.

### 2.4 Phase 2 checklist

- [ ] Site created with PHP 8.5 (or 8.4, noted), site user `<SITE_USER>`.
- [ ] Vhost root ends in `/public`.
- [ ] Let's Encrypt certificate installed; `http://` redirects to `https://`.
- [ ] Varnish is off.

---

## 3. PHP extensions and PHP settings

### 3.1 Extensions the code really needs

Checked against `composer.json`/`composer.lock` (PhpSpreadsheet, Dompdf, Google API client, Maatwebsite Excel, OpenSpout) and the application code.

| Extension | Why | Required? |
|---|---|---|
| `pdo_mysql` (mysqlnd) | MySQL | **yes** |
| `mbstring`, `ctype`, `json`, `tokenizer`, `filter`, `iconv`, `hash`, `session`, `pcre` | Laravel core | **yes** |
| `openssl`, `curl` | Google APIs (MDM), Arkesel HTTP, mail TLS, JWT verification, encrypted signatures | **yes** |
| `xml`, `dom`, `libxml`, `simplexml`, `xmlreader`, `xmlwriter` | PhpSpreadsheet (xlsx import/export), `XlsxStreamReader` (customer list), Dompdf | **yes** |
| `zip`, `zlib` | xlsx files are zip archives | **yes** |
| `gd` (with JPEG, PNG, WebP, GIF) | Blog pictures, Finance/H&S/G-Water photo checks, signatures, PhpSpreadsheet | **yes** |
| `fileinfo` | content-type checks on every upload | **yes** |
| `pcntl`, `posix` | the queue worker enforces `--timeout` and stops gracefully only with `pcntl` (CLI) | **yes** (CLI) |
| `opcache` | speed | strongly recommended (section 16) |
| `intl` | recommended by Symfony/Laravel; not called directly by the app | recommended |
| `bcmath` | optional (listed by dependencies, not called by the app code) | optional |
| `exif` | only used by H&S incident photos, and only when present (`function_exists('exif_read_data')`); the Blog reads orientation from the file bytes | optional |
| `redis` | only if you switch cache/queue to Redis (section 16) | optional |
| `sodium` | not used by this app | no |

CloudPanel's PHP packages normally ship all of these. Check **as `<SITE_USER>`** (the CLI is a different PHP configuration from FPM, see 3.2):

```bash
# Run as: <SITE_USER>
<PHP> -v
<PHP> -m | grep -iE '^(pdo_mysql|mbstring|openssl|curl|dom|xml|xmlreader|xmlwriter|simplexml|libxml|zip|zlib|gd|fileinfo|pcntl|posix|iconv|intl|bcmath|exif|Zend OPcache)$' | sort
<PHP> -r 'print_r(array_intersect_key(gd_info(), array_flip(["JPEG Support","PNG Support","WebP Support","GIF Read Support"])));'
```

Good result: every name above is listed (`bcmath`/`exif`/`intl` may be absent), and the four `gd_info` lines are `1`.

If one is missing (Run as: `root`; package names assume the Ondrej PHP packages CloudPanel uses, VERIFY):

```bash
apt install php8.5-gd php8.5-zip php8.5-xml php8.5-mbstring php8.5-curl php8.5-intl php8.5-bcmath php8.5-mysql
systemctl restart php8.5-fpm
```

### 3.2 PHP settings (CloudPanel UI)

Uploads that matter (the app's own limits come from `.env`; PHP and Nginx must be **at or above** them):

| Upload | `.env` limit | Default |
|---|---|---|
| Customer List workbook (plain form post) | `GWL_COMMERCIAL_CUSTOMER_IMPORT_MAX_MB` | 200 MB |
| Detailed Billing Report | `GWL_COMMERCIAL_BILLING_DETAILS_IMPORT_MAX_MB` | 250 MB |
| Other Commercial reports | `GWL_COMMERCIAL_IMPORT_MAX_MB` | 10 MB |
| Blog picture / cover | `GWL_BLOG_MEDIA_MAX_MB` / `GWL_BLOG_COVER_MAX_MB` | 8 / 3 MB |
| Assets import | `GWL_ASSETS_IMPORT_MAX_MB` | 5 MB |

CloudPanel UI: site -> **Settings** -> **PHP Settings** (VERIFY the tab name), set:

| Setting | Value | Reason |
|---|---|---|
| `memory_limit` | `768M` (use `512M` on the 8 GB server) | PhpSpreadsheet reading a 10 MB report, 5000-row Excel/PDF exports |
| `upload_max_filesize` | `260M` | above the 250 MB billing-details limit |
| `post_max_size` | `270M` | above the upload limit |
| `max_execution_time` | `600` | synchronous exports and report reads; the huge imports run in the queue |
| `max_input_time` | `600` | a 250 MB upload over a slow link |
| `max_input_vars` | `3000` | |
| `display_errors` | `Off` | never show errors to visitors |

Save, then confirm (the page must be reachable; use a temporary file only if you have no other way, and **delete it**):

```bash
# Run as: <SITE_USER>   (FPM values, not the CLI ones; check in CloudPanel's Settings page instead if you prefer)
grep -E 'upload_max_filesize|post_max_size|memory_limit|max_execution_time' /etc/php/8.5/fpm/pool.d/*.conf /etc/php/8.5/fpm/php.ini 2>/dev/null | head
```

> **The CLI is separate.** Queue workers and `artisan` use `/etc/php/8.5/cli/php.ini` (typically `memory_limit=128M`). That is why the worker command in section 9 passes `-d memory_limit=1024M`, and why you can see "works in the browser, runs out of memory in the terminal".

### 3.3 Nginx request body limit (CloudPanel UI)

1. Site -> **Vhost**.
2. Find (or add inside the `server { ... }` block) and set:

```nginx
client_max_body_size 300m;       # VERIFY: the CloudPanel template usually ships a smaller value
client_body_timeout  300s;
fastcgi_read_timeout 600s;       # VERIFY: must be >= max_execution_time
```

3. **Save** (CloudPanel tests the config and reloads Nginx). If you get a test error, the page shows it; fix and save again.

### 3.4 Phase 3 checklist

- [ ] `<PHP> -m` shows every required extension; `gd_info` shows WebP.
- [ ] PHP Settings saved (memory 768M, upload 260M, post 270M, time 600).
- [ ] Vhost `client_max_body_size 300m` saved without errors.

---

## 4. Database

### 4.1 Why production must be MySQL, not SQLite

* `.env.example` defaults to SQLite for developers and tests only.
* The Commercial **Customer List** is built on **set-based SQL** that exists in two versions: `CustomerSql` branches on the driver and uses MySQL's `UPDATE ... JOIN` for MySQL/MariaDB (and `UPDATE ... FROM` for SQLite), keyset pages with row-value comparisons, and a merge that rewrites hundreds of thousands of rows per statement. The benchmark and the performance notes were measured on **MySQL 8.4**. SQLite has one writer at a time: the web app, two queue workers and the scheduler would constantly hit "database is locked".
* MySQL **8.4** is the tested engine. MariaDB is accepted by `CustomerSql` (it checks `mysql` and `mariadb`) but was never benchmarked: use it only if the organisation requires it, and then run the whole smoke test of section 15 twice (VERIFY).

### 4.2 Create the database and user (CloudPanel UI)

1. Site -> **Databases** -> **Add Database**.
2. **Database Name** `<DB>`, **Database User Name** `<DB_USER>`, **Password** (generate a long one; store it in the password manager).
3. Create. The user is limited to this database and to `127.0.0.1`/localhost.

### 4.3 Check and fix the charset before the first migration

The application is configured for `utf8mb4` / `utf8mb4_unicode_ci` (`config/database.php`, keys `DB_CHARSET`, `DB_COLLATION`). MySQL 8's own default collation is different (`utf8mb4_0900_ai_ci`), and mixing the two causes "Illegal mix of collations" errors in joins and the case-insensitive uniqueness checks (supplier names, PV numbers). Check and, if needed, fix **before** migrating:

```bash
# Run as: root
mysql -e "SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='<DB>';"
# If it is not utf8mb4 / utf8mb4_unicode_ci (the database is still empty, so this is safe):
mysql -e "ALTER DATABASE <DB> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "SELECT @@global.time_zone, NOW(), UTC_TIMESTAMP();"      # NOW() and UTC_TIMESTAMP() should show the same time (UTC+0)
```

### 4.4 The `.env` database lines

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<DB>
DB_USERNAME=<DB_USER>
DB_PASSWORD=<the password from 4.2>
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

Use `127.0.0.1` (TCP), not `localhost`. MySQL stays bound to `127.0.0.1` only (checked in section 12). MySQL tuning for the Customer List is in section 16.3.

### 4.5 Phase 4 checklist

- [ ] Database and user created in CloudPanel.
- [ ] Charset/collation is `utf8mb4` / `utf8mb4_unicode_ci`.
- [ ] `NOW()` equals `UTC_TIMESTAMP()`.
- [ ] Password stored in the password manager.

---

## 5. Get the code onto the server

> Deploy a **committed and tagged** commit from `main`, never a working copy. (The branch this guide was written on, `feat/assets-mdm`, has many uncommitted changes: merge and tag first. Problems found, item 15.)

### 5.1 Deploy key (read-only) for git

```bash
# Run as: <SITE_USER>   (log in with:  su - <SITE_USER>   or  ssh <SITE_USER>@<DOMAIN>)
ssh-keygen -t ed25519 -f ~/.ssh/erp_deploy -C "erp-deploy-$(hostname)" -N ""
cat >> ~/.ssh/config <<'EOF'
Host github-erp
  HostName github.com
  User git
  IdentityFile ~/.ssh/erp_deploy
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config ~/.ssh/erp_deploy
cat ~/.ssh/erp_deploy.pub
```

On GitHub: repository -> **Settings** -> **Deploy keys** -> **Add deploy key**, paste the public key, leave **Allow write access** unticked. (If you prefer a token instead of a key, use a fine-grained token with read-only *Contents* access and an `https://` clone URL; never store it in the repo.)

### 5.2 Clone

CloudPanel creates the site folder with placeholder files, and `git clone` refuses a non-empty folder.

```bash
# Run as: <SITE_USER>
cd /home/<SITE_USER>/htdocs/<DOMAIN>
ls -A                                  # look first; remove only CloudPanel's placeholder files
rm -rf ./* ./.[!.]* 2>/dev/null; ls -A # must print nothing
git clone git@github-erp:<ORG>/<REPO>.git .      # today origin is kinfays/Gwl_erp_mini (confirm the final repository)
git fetch --tags
git checkout --detach <TAG>            # e.g. v1.0.0 (or: git checkout main)
git log -1 --oneline
```

### 5.3 PHP dependencies

Find Composer first (VERIFY the path: CloudPanel installs it for site users):

```bash
# Run as: <SITE_USER>
which composer ; composer --version
cd <APP_DIR>
COMPOSER_MEMORY_LIMIT=-1 <PHP> $(which composer) install --no-dev --optimize-autoloader --no-interaction
```

Notes:

* **The first install is slow** (several minutes). `composer.json` runs `Google\Task\Composer::cleanup` in `pre-autoload-dump`, which deletes about 670 unused Google API service folders so that only `AndroidManagement` and `Pubsub` remain. That deletion happens on every autoload dump, which is also why `vendor/google/apiclient-services` looks nearly empty afterwards. Do not "repair" it.
* `--no-dev` leaves out PHPUnit, Pail and Pint. `laravel/tinker` is a normal dependency, so `artisan tinker` still works (used in 7.3).
* `config.platform.php` is `8.5.0`, so Composer does not complain on PHP 8.4. It would not catch a package that really needs 8.5 (none does today).
* Always run Composer through the same `<PHP>` the site uses (the default `php` in the shell can be a different version).

### 5.4 Front-end build (Node 22+)

`public/build` is git-ignored, so **the build must be done on the server** (or copied there) or every page fails with "Vite manifest not found".

```bash
# Run as: <SITE_USER>   (installs Node for this user only, no root needed)
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash     # VERIFY: current nvm version
source ~/.nvm/nvm.sh
nvm install 22 && nvm alias default 22
node -v                                   # v22.x
cd <APP_DIR>
npm ci --no-audit --no-fund
npm run build
ls public/build/manifest.json             # must exist
```

Good result: `vite build` ends with `built in ...s` and `manifest.json` exists. All fonts and libraries (Chart.js, TipTap, signature_pad) come from npm, so the server needs no CDN access.

**`node_modules` can be deleted after the build** (`rm -rf node_modules`, it is several hundred MB and nothing at run time uses it); `npm ci` recreates it on the next deploy. The deploy script in section 14 does exactly that.

### 5.5 Phase 5 checklist

- [ ] Deploy key added (read-only) and `git clone` works.
- [ ] A **tag** (not a branch) is checked out.
- [ ] `composer install --no-dev` finished without errors.
- [ ] `public/build/manifest.json` exists.

---

## 6. The production `.env`

Create it as `<SITE_USER>`: `cp .env.example .env && chmod 640 .env`, then edit it to match the template below. Remember `.env.example` is a **developer** file: it defaults to `APP_ENV=local`, `APP_DEBUG=true`, SQLite, and does **not** list every setting (Problems found, items 2 and 3). Use this template instead.

### 6.1 Decision: database queue, database cache and sessions

| Choice | Decision | Why |
|---|---|---|
| Queue | `database` | Already the default and what `docs/assets/mdm.md` assumes; the `jobs`, `job_batches`, `failed_jobs` tables ship with the migrations; no extra service to secure, monitor or back up; jobs survive a restart and are in the same backup as the data; the volume (minutes-long imports, a few thousand SMS chunks) is tiny. |
| Cache | `database` | Supports the atomic locks the app needs (`Cache::lock` for customer-list districts, SMS campaigns, MDM events, and `withoutOverlapping()` in the scheduler). |
| Sessions | `database` | Already the default; survives restarts. |
| Redis | optional later | Section 16.4 says when and how. |

### 6.2 Annotated template

```dotenv
############ Core ############
APP_NAME="<ORGANISATION> ERP"        # also becomes the session cookie name and the mail "From" name
APP_ENV=production                   # MUST be production: the demo-data commands only refuse to run when it is
APP_KEY=                             # SECRET. Filled by `php artisan key:generate --force` in 7.1. Back it up.
APP_DEBUG=false                      # never true in production
APP_URL=https://<DOMAIN>             # exact public address, no trailing slash
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

############ Logging ############
LOG_CHANNEL=stack
LOG_STACK=daily                      # one file per day instead of one ever-growing laravel.log
LOG_DAILY_DAYS=30
LOG_LEVEL=warning
LOG_DEPRECATIONS_CHANNEL=null

############ Database (section 4) ############
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<DB>
DB_USERNAME=<DB_USER>
DB_PASSWORD=                         # SECRET
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

############ Sessions, cache, queue ############
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true           # not in .env.example: without it the cookie is not marked Secure
SESSION_SAME_SITE=lax
SESSION_PATH=/
SESSION_DOMAIN=null
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=7500            # REQUIRED and missing from .env.example. Must be LONGER than the worker --timeout (7300)
                                     # which is longer than the longest job (7200 s). The default (90 s) would hand a running
                                     # 2-hour import to a second worker. Trade-off: a job whose worker is killed is retried only
                                     # after 7500 s (about 2 hours).
FILESYSTEM_DISK=local                # private disk: storage/app/private
BROADCAST_CONNECTION=log

############ Mail (leave emails, invitations, password reset, notices) ############
MAIL_MAILER=smtp
MAIL_HOST=<smtp host>
MAIL_PORT=587                        # 587 with STARTTLS: MAIL_SCHEME=null; port 465: MAIL_SCHEME=smtps (VERIFY with the provider)
MAIL_SCHEME=null
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=                       # SECRET
MAIL_FROM_ADDRESS="erp@<your-domain>"
MAIL_FROM_NAME="${APP_NAME}"
GWL_LEAVE_EMAIL_NOTIFICATIONS_ENABLED=true     # false stops every leave e-mail (in-app bell is unaffected)

VITE_APP_NAME="${APP_NAME}"

############ Feature flags: each one decides whether the routes, menus AND schedules exist ############
# Routes are registered at boot from these, so after changing any of them run `config:cache` AND `route:cache`
# (the `php artisan optimize` in section 14 does both) and restart the workers.
GWL_COMMERCIAL_MODULE_ENABLED=false            # Commercial: needs queue worker + scheduler + the PHP/Nginx limits of section 3
GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED=true      # only has an effect while Commercial is on; millions of rows: MySQL tuning (16.3) first
GWL_COMMERCIAL_CUSTOMER_IMPORT_MAX_MB=200
GWL_COMMERCIAL_BILLING_DETAILS_IMPORT_MAX_MB=250
GWL_COMMERCIAL_IMPORT_MAX_MB=10
GWL_COMMERCIAL_SMS_ENABLED=false               # needs Commercial + customer list ON, queue worker, scheduler, Arkesel (section 11.3)
GWL_HEALTH_SAFETY_MODULE_ENABLED=false         # scheduler (hourly + daily alerts), mail, GWL_HS_QR_BASE_URL
GWL_HS_QR_BASE_URL=https://<DOMAIN>            # printed on QR labels and posters: ALWAYS the production address (blank = APP_URL)
GWL_BLOG_MODULE_ENABLED=true                   # default on; needs GD(WebP); daily blog:prune-media (scheduler)
GWL_GWATER_MODULE_ENABLED=false                # scheduler (gwater:remind)
GWL_FINANCE_AUDIT_ENABLED=false                # Finance + Audit (one workflow). NOT in .env.example; default false. scheduler
GWL_MDM_ENABLED=false                          # Android Enterprise: Google credentials, Pub/Sub, queue worker, scheduler, public webhook

############ MDM (only when GWL_MDM_ENABLED=true; full steps in docs/assets/mdm.md) ############
GOOGLE_CLOUD_PROJECT_ID=
GOOGLE_APPLICATION_CREDENTIALS=/home/<SITE_USER>/secrets/gwl-amapi.json   # SECRET FILE: absolute path, outside htdocs, chmod 600
ANDROID_MANAGEMENT_ENTERPRISE_ID=              # filled once, after `php artisan mdm:enterprise-create`
GWL_MDM_PUBSUB_TOPIC=
GWL_MDM_PUBSUB_SUBSCRIPTION=
GWL_MDM_PUBSUB_MODE=pull                       # start with pull; switch to push after the checklist in 11.1
GWL_MDM_PUBSUB_PUSH_AUDIENCE=                  # push only
GWL_MDM_PUBSUB_PUSH_SERVICE_ACCOUNT=           # push only: e-mail of the dedicated push service account
GWL_MDM_PUBSUB_PUSH_TOKEN=                     # SECRET, push only: php -r 'echo bin2hex(random_bytes(32));'
GWL_MDM_ENROLLMENT_TOKEN_MINUTES=60
GWL_MDM_LOST_MODE_MESSAGE="This phone is company property and has been reported lost. Please contact the number shown to return it."
GWL_MDM_LOST_MODE_PHONE=
GWL_MDM_LOST_MODE_ADDRESS=

############ SMS (only when GWL_COMMERCIAL_SMS_ENABLED=true; docs/16-module-commercial-sms.md) ############
GWL_COMMERCIAL_SMS_REGIONS="ACCRA WEST"        # the only region that can be messaged
GWL_ARKESEL_API_KEY=                           # SECRET
GWL_ARKESEL_SENDER_ID=                         # approved sender IDs, comma separated, 11 characters at most each; first = default
GWL_ARKESEL_SANDBOX=true                       # keep TRUE until the first real send has been agreed in writing (11.3)
GWL_ARKESEL_WEBHOOK_TOKEN=                     # SECRET: php -r 'echo bin2hex(random_bytes(32));'  (empty = webhook is off)
GWL_ARKESEL_BASE_URL=https://sms.arkesel.com
GWL_ARKESEL_TIMEOUT=15

############ Everything else is optional ############
GWL_AUTH_BACKGROUND=video                      # sign-in page background: video | gif
```

All the other `GWL_*` keys (thresholds, row caps, retention, reminder days, Finance/Audit and Assets limits...) have safe defaults in `config/gwl.php` and most can be changed on the module's own Settings screen. Add them to `.env` only when you have a reason; `config/gwl.php` is the list (about 200 keys).

### 6.3 Which values must be secret

Everything marked **SECRET** above, plus the whole `.env` file and the Google JSON file. The `.env` file is git-ignored; keep it `chmod 640` and owned by `<SITE_USER>`. Do not put real values in tickets, screenshots or chats.

### 6.4 After any `.env` edit

> **`php artisan config:cache` again.** With the config cached the app **never reads `.env`** (no code in `app/`, `routes/`, `bootstrap/app.php`, seeders or migrations calls `env()` directly; this was checked), so an edited `.env` changes nothing until you run `php artisan config:cache`. A flag change also needs `php artisan route:cache` and `queue:restart` (`php artisan optimize` + `queue:restart` does all three). Section 14 has the exact lines.

### 6.5 Phase 6 checklist

- [ ] `.env` created from the template, mode 640, owned by `<SITE_USER>`.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<DOMAIN>`.
- [ ] `DB_QUEUE_RETRY_AFTER=7500` is present.
- [ ] Only the modules you will use on day one are `true`.
- [ ] No secret was pasted anywhere except the server and the password manager.

---

## 7. First-time setup

All commands **as `<SITE_USER>`** in `<APP_DIR>`. Define a shortcut once per shell: `alias art="<PHP> artisan"`.

### 7.1 Key

```bash
art key:generate --force
grep '^APP_KEY=base64:' .env | cut -c1-20     # prints APP_KEY=base64:xxxxxx ; copy the full key to the password manager NOW
```

### 7.2 Rehearse the migrations on a scratch database (recommended, once)

Several migrations were only ever run on SQLite or on a developer MySQL, and an earlier MySQL-only bug (a BIGINT foreign key to an INT UNSIGNED column) got through exactly because SQLite does not check (see `CLAUDE.md`, "Migration conventions"). Prove a full run on empty MySQL **before** touching the real database:

```bash
# Run as: root
mysql -e "CREATE DATABASE erp_rehearsal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON erp_rehearsal.* TO '<DB_USER>'@'localhost';"
# Run as: <SITE_USER>
DB_DATABASE=erp_rehearsal <PHP> artisan migrate --force 2>&1 | tail -20
DB_DATABASE=erp_rehearsal <PHP> artisan migrate:status | grep -c Pending      # must print 0
# Run as: root
mysql -e "DROP DATABASE erp_rehearsal;"
```

Good result: no `SQLSTATE` error and `0` pending. (This is the only place a `DROP DATABASE` appears in this guide; it targets the scratch database by name.) If it fails, stop and report the error to the developers; do not continue.

### 7.3 Migrate and seed the real database

```bash
art migrate --force
art migrate:status | grep -c Pending          # 0
art db:seed --force
```

`DatabaseSeeder` runs, in this order: `RoleSeeder` -> `PermissionSeeder` -> `ModuleAccessSeeder` -> `StaffRolePermissionSeeder` -> `LettersRolePermissionSeeder` -> `VisitorsRolePermissionSeeder` -> `UacRolePermissionSeeder` -> `LeaveApprovalRolePermissionSeeder` -> `AssetsRolePermissionSeeder` -> `TransportRolePermissionSeeder` -> `CommercialRolePermissionSeeder` -> `HealthSafetyRolePermissionSeeder` -> `RegionalBlogRolePermissionSeeder` -> `GwaterRolePermissionSeeder` -> `FinanceAuditRolePermissionSeeder` -> `MdmStarterPolicySeeder` -> `TransportSeeder` -> `HolidaySeeder` -> `SuperAdminSeeder` (last). Run it **once**; permissions, module access and role grants are also inserted by migrations, so a normal deploy only needs `migrate`, never `db:seed`.

Seeders in `database/seeders/` that are **not** run and must **not** be run on production:

* `LeavePermissionsSeeder`, `LeaveRolePermissionSeeder`: legacy, superseded by the migrations.
* `DemoDataSeeder` (and `database/seeders/Demo/`): invents staff, leave, letters, assets. It has **no production guard** (Problems found, item 9).
* `FinanceAuditDemoSeeder`: demo vouchers. Never.

### 7.4 Set the first super admin (do this before anyone else can reach the site)

`SuperAdminSeeder` creates the user with **staff ID `21475`**, e-mail `superadmin@ml.local` and the **fixed password `Admin@12`**, with `must_change_password = false`. Anyone who reads the repository knows it. Replace the password immediately and force a change at the first login:

```bash
# Run as: <SITE_USER>
read -rs -p "New super admin password (at least 12 characters): " NEWPW; echo
NEWPW="$NEWPW" <PHP> artisan tinker --execute='
  $u = App\Models\User::where("staff_id", "21475")->firstOrFail();
  $u->forceFill(["password" => getenv("NEWPW"), "must_change_password" => true, "email" => "<your real e-mail>"])->save();
  echo "ok\n";
'
unset NEWPW
```

(The `User` model hashes the password through its `hashed` cast, so give it the **plain** text, not `Hash::make(...)`.) Good result: prints `ok`; logging in with `Admin@12` now fails, and logging in with the new password forces a password change. Then create the real administrators in **UAC**, and keep this account as a break-glass account with a long unique password (VERIFY whether UAC lets you rename or deactivate the seeded staff ID before you decide to retire it).

### 7.5 Storage link, caches, optimise

```bash
art storage:link               # creates public/storage -> storage/app/public (needed: see 8.3)
art config:cache
art route:cache                # works with this app's closure routes (checked 2026-11-16)
art view:cache
art event:cache                # also caches the discovered Finance listener (it is NOT registered by hand)
art optimize                   # runs all four again; safe
art about | head -30           # Environment: production, Debug Mode: OFF, Cache: ... 
```

> Warning, repeated on purpose: **after every `.env` edit run `php artisan config:cache` again.**

### 7.6 Phase 7 checklist

- [ ] `APP_KEY` generated and copied to the password manager.
- [ ] Rehearsal migration passed (0 pending) and the scratch database was dropped.
- [ ] `migrate --force` and `db:seed --force` done once.
- [ ] Super admin `21475` password replaced; `Admin@12` no longer works.
- [ ] `storage:link`, `config:cache`, `route:cache`, `view:cache`, `event:cache` done; `art about` shows `production` and Debug `OFF`.

---

## 8. File permissions and storage

### 8.1 Ownership

PHP-FPM runs as `<SITE_USER>` (a CloudPanel pool per site), so no `www-data`, no `chmod 777`, no group tricks.

```bash
# Run as: <SITE_USER>
cd <APP_DIR>
chmod -R u+rwX,g+rX,o-rwx storage bootstrap/cache
chmod 640 .env
# If anything was ever created by root, fix it as root:
#   chown -R <SITE_USER>:<SITE_USER> <APP_DIR>
find . -maxdepth 2 ! -user <SITE_USER> -not -path './node_modules/*' 2>/dev/null | head      # must print nothing
```

### 8.2 Where the private files are (never served by Nginx)

The Nginx root is `public/`, and the `local` disk is `storage/app/private` (`config/filesystems.php`). None of these is reachable by URL; each is delivered only through an authorised route.

| Folder under `storage/app/private/` | What | Delivered by |
|---|---|---|
| `leave-signatures/` | leave-letter signatures, **encrypted with `APP_KEY`** | PDF generation only (disk `leave_signatures`) |
| `letters/scans/` | optional scans of hardcopy letters (`GWL_LETTERS_SCANS_ENABLED`) | `LetterScanController` |
| `blog/media/`, `blog/covers/` | Regional Blog pictures | `blog.media` / `blog.cover` routes |
| `gwater/waybills/` | G-Water waybill photos/PDFs | `gwater.purchases.waybill` route |
| `finance/pvs/` | payment-voucher attachments | scope-checked Finance/Audit routes |
| `health_safety/incidents/` and equipment attachments | incident and equipment photos | H&S routes |
| `commercial/imports/`, `commercial/billing-details/`, `commercial/customer-imports/` | uploaded workbooks (customer files hold names and phones) | never served; deleted when processing finishes |
| `commercial/customer-exports/` and `commercial-export-*.xlsx` | generated exports (personal data) | download route, then discarded |
| `import-tmp/`, `livewire-tmp/` | temporary upload parts | temporary |

### 8.3 `storage:link`: needed, because two features use the public disk

`storage:link` is **not** skipped. Two features store on the `public` disk (`storage/app/public`, served as `https://<DOMAIN>/storage/...`):

* **Leave supporting documents** (`storage/app/public/leave_attachments`, `ApplyForm`), linked from the approvals and history screens with `asset('storage/...')`.
* **Asset model pictures** (`storage/app/public/ict-asset-models`).

Anyone who knows or guesses a file URL can open these **without logging in**. The names are random, but this is not "private". See Problems found, item 5; until the developers move leave documents to the private disk, tell HR not to attach sensitive documents, or restrict `/storage/leave_attachments/` at Nginx if you can accept the links breaking.

### 8.4 What to back up

| Back up | Why |
|---|---|
| The **database** | everything |
| `storage/app/private/` (all of it) | uploaded evidence, attachments, signatures, scans |
| `storage/app/public/` | leave documents, model pictures |
| `.env` and the Google JSON file | **kept separately, encrypted**, not next to the data backups |
| **`APP_KEY`** | without it signatures and encrypted MDM payloads cannot be read |

Do **not** back up `vendor/`, `node_modules/`, `bootstrap/cache/`, `storage/framework/` (caches, sessions, compiled views), or `public/build/` (rebuilt from git).

### 8.5 Phase 8 checklist

- [ ] No file in `<APP_DIR>` is owned by root.
- [ ] `storage/` and `bootstrap/cache/` are writable by `<SITE_USER>`.
- [ ] `curl -sI https://<DOMAIN>/storage/` returns 403/404 (no directory listing) and `public/storage` exists.
- [ ] Backup list (8.4) copied into the backup plan (section 13).

---

## 9. Queue worker (Supervisor)

### 9.1 What the app needs

The app **requires** a worker: `ProcessCustomerListBatch`, `ProcessBillingDetailsBatch`, `VoidCustomerListUpload/Batch`, `BuildCustomerListExport`, `SendSmsCampaignChunk`, `ApplySmsDeliveryReport`, and the MDM jobs `ProcessAndroidNotification`, `SendMdmCommand`, `SyncMdmDevice`, plus queued notifications. Facts verified in the code:

* **Every job uses the single `default` queue** (nothing calls `onQueue()` and no job sets `$queue`), so there are **no queue names** to configure; the worker must listen to `default`.
* Job time limits: Customer List import/void **7200 s**, Detailed Billing **7200 s**, customer export 3600 s, SMS chunk 900 s; `$tries`: 5, 3, 1, unlimited (`maxExceptions = 1`), MDM jobs 3. The worker `--timeout` must be at least the longest job, and `DB_QUEUE_RETRY_AFTER` must be longer than the worker timeout: **7500 > 7300 > 7200**.
* While the site is in maintenance mode (`artisan down`) **workers do not run jobs**.
* One import per district at a time (a cache lock, 4 hours); a long import occupies one worker for up to two hours. So run **two worker processes**: one can be busy with an import while the other still sends SMS, MDM commands and notices. Raise `numprocs` to 3 if several districts are imported together (about 1 GB RAM each at most).

### 9.2 Install Supervisor (Run as: `root`)

CloudPanel does not include Supervisor.

```bash
apt -y install supervisor
systemctl enable --now supervisor
supervisorctl status          # prints nothing yet, no error
```

### 9.3 Worker configuration (Run as: `root`)

```bash
cat > /etc/supervisor/conf.d/erp-worker.conf <<'EOF'
[program:erp-worker]
process_name=%(program_name)s_%(process_num)02d
command=<PHP> -d memory_limit=1024M <APP_DIR>/artisan queue:work --sleep=3 --tries=3 --timeout=7300 --max-time=3600 --memory=768
directory=<APP_DIR>
user=<SITE_USER>
numprocs=2
autostart=true
autorestart=true
startsecs=5
stopasgroup=true
killasgroup=true
stopwaitsecs=7500
redirect_stderr=true
stdout_logfile=/home/<SITE_USER>/logs/erp-worker.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
EOF
```

Replace `<PHP>`, `<APP_DIR>` and `<SITE_USER>` with the real values (supervisor does not expand shell variables; `/home/<SITE_USER>/logs/` is CloudPanel's per-site log folder, VERIFY it exists or use `/var/log/erp-worker.log`).

| Flag | Value | Why |
|---|---|---|
| `-d memory_limit=1024M` | 1 GB | the CLI default is 128 MB; imports stream, but exports and PDFs need room |
| `--timeout=7300` | > 7200 | longest job plus margin; `pcntl` enforces it |
| `--tries=3` | default for jobs that set no `$tries` | jobs that set their own keep it |
| `--max-time=3600` | restart each hour | an idle worker restarts and picks up new code/config; a running job is **never** cut short by it |
| `--memory=768` | MB | the worker exits (and Supervisor restarts it) after a job if it grew past this |
| `stopwaitsecs=7500` | > timeout | `supervisorctl stop/restart` waits for the current job to finish instead of killing it |
| `numprocs=2` | | see 9.1 |

Start it:

```bash
supervisorctl reread && supervisorctl update
supervisorctl status          # erp-worker:erp-worker_00 RUNNING, erp-worker:erp-worker_01 RUNNING
```

*(systemd alternative, if you prefer it: one unit `erp-worker@.service` with `User=<SITE_USER>`, `WorkingDirectory=<APP_DIR>`, the same `ExecStart=<PHP> -d memory_limit=1024M artisan queue:work ...`, `Restart=always`, `RestartSec=5`, `TimeoutStopSec=7500`; enable `erp-worker@1` and `erp-worker@2`. Do not run both Supervisor and systemd workers.)*

### 9.4 Restart on deploy and check that it is alive

```bash
# Run as: <SITE_USER>
<PHP> artisan queue:restart        # asks every worker to finish its job and exit; Supervisor starts fresh ones
```

Check it is alive and working:

```bash
# Run as: root
supervisorctl status
tail -n 20 /home/<SITE_USER>/logs/erp-worker.log

# Run as: <SITE_USER>   (a test job that only writes a log line)
cd <APP_DIR>
<PHP> artisan tinker --execute='dispatch(function () { logger()->warning("queue test ok"); }); echo "queued\n";'
sleep 8; grep "queue test ok" storage/logs/laravel-$(date +%F).log | tail -1     # must print a line
mysql -e "SELECT COUNT(*) AS waiting FROM <DB>.jobs; SELECT COUNT(*) AS failed FROM <DB>.failed_jobs;"   # (as root)
<PHP> artisan queue:failed                                  # lists failed jobs
<PHP> artisan queue:retry all                               # retries them (only after the cause is fixed)
```

### 9.5 Phase 9 checklist

- [ ] `supervisorctl status` shows two RUNNING workers.
- [ ] The test job wrote `queue test ok`.
- [ ] `DB_QUEUE_RETRY_AFTER=7500` is in `.env` and `config:cache` was re-run.
- [ ] Everyone knows: do not deploy while an import is running (check the batch pages first).

---

## 10. Scheduler (cron)

### 10.1 Add the cron job (CloudPanel UI)

1. Site -> **Cron Jobs** -> **Add Cron Job**.
2. Schedule: **Every minute** (`* * * * *`).
3. Command (one line):

```bash
cd /home/<SITE_USER>/htdocs/<DOMAIN> && <PHP> artisan schedule:run >> /dev/null 2>&1
```

4. Save. CloudPanel installs it in `<SITE_USER>`'s crontab (so it runs as that user; never add it to root's).

Check:

```bash
# Run as: <SITE_USER>
crontab -l | grep schedule:run                       # the line is there
cd <APP_DIR> && <PHP> artisan schedule:list           # lists next-run times for the commands your flags enable
# after two minutes (Run as: root):
grep CRON /var/log/syslog | grep <SITE_USER> | tail -3   # VERIFY: log file name on Ubuntu 24.04 (journalctl -u cron)
```

### 10.2 What the scheduler runs (from `routes/console.php`)

All times are the application timezone, which is **UTC** (`config/app.php` is fixed to `'UTC'`; Ghana is UTC+0, so they equal wall-clock time).

| Command | When | Condition |
|---|---|---|
| `gwcl:auto-checkout-visitors` | daily 18:00 (`GWL_AUTO_CHECKOUT_TIME`) | always |
| `leave:generate-entitlements` | daily 00:20 | always |
| `leave:forfeit-expired-carry-over` | daily 00:30 | always |
| `transport:check-expiries` | daily 07:30 | always |
| `blog:prune-media` | daily 03:20 | `GWL_BLOG_MODULE_ENABLED` |
| `mdm:poll-events` | every minute (does nothing in push mode) | `GWL_MDM_ENABLED` |
| `mdm:sync-devices` / `mdm:prune-events` | 02:15 / 03:00 | `GWL_MDM_ENABLED` |
| `commercial:remind-uploads` | 07:45 | `GWL_COMMERCIAL_MODULE_ENABLED` |
| `commercial:billing-details:purge` | 03:35 | Commercial |
| `commercial:remind-customer-uploads` / `commercial:customers:purge` | 07:50 / 03:30 | Commercial + `GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED` |
| `commercial:sms:run-due` | every minute | SMS flag (and Commercial and the customer list) |
| `commercial:sms:poll-delivery` | every 10 minutes | SMS |
| `commercial:sms:check-balance` / `commercial:sms:purge` | 07:55 / 03:40 | SMS |
| `gwater:remind` | 07:55 | `GWL_GWATER_MODULE_ENABLED` |
| `finance:remind` and `audit:remind` | 08:00 | `GWL_FINANCE_AUDIT_ENABLED` |
| `health-safety:send-alerts --group=acknowledgement` | hourly | `GWL_HEALTH_SAFETY_MODULE_ENABLED` |
| `health-safety:send-alerts --group=all` | daily 06:30 | H&S |

Most use `withoutOverlapping()`, which keeps a lock in the cache table; after a crash a stale lock expires by itself, or clear it with `php artisan schedule:clear-cache` (as `<SITE_USER>`).

### 10.3 Phase 10 checklist

- [ ] The cron line exists in `<SITE_USER>`'s crontab.
- [ ] `schedule:list` shows the expected commands for the enabled flags.
- [ ] After a few minutes a scheduled command has left a trace (for example the SMS/MDM minute jobs in the log, or `schedule:run -v` output).

---

## 11. Integrations that need the outside world

### 11.1 MDM webhook (Android Enterprise, push mode)

Only when `GWL_MDM_ENABLED=true`. Start in **pull** mode (`GWL_MDM_PUBSUB_MODE=pull`): the scheduler's `mdm:poll-events` fetches events every minute and needs no inbound access. Move to **push** when the public URL is verified. The full cloud-side checklist is `docs/assets/mdm.md` ("Going live: pull -> push checklist"); the server-side requirements are:

* **Endpoint:** `POST https://<DOMAIN>/webhooks/android-management?token=<GWL_MDM_PUBSUB_PUSH_TOKEN>`.
* The route is outside login, sessions and CSRF (it drops the `web` middleware group), throttled at `GWL_MDM_WEBHOOK_RATE_PER_MINUTE` (600/min per source IP), and authenticated by **Google's OIDC token** (audience = `GWL_MDM_PUBSUB_PUSH_AUDIENCE`, e-mail = `GWL_MDM_PUBSUB_PUSH_SERVICE_ACCOUNT`) **plus** the `?token=` secret. It stores an event row and queues `ProcessAndroidNotification`; it never calls Google itself.
* **Must be reachable by Google:** no CloudPanel **Basic Auth** on the site (Site -> **Security**, VERIFY the name), no IP allow-list on the site, no bot-blocking rule or external WAF that rejects Google's POSTs, valid certificate (Let's Encrypt is fine), and no `down` maintenance window (a 503 is simply retried by Pub/Sub).
* Subscription settings on Google's side: push endpoint as above, authentication = the dedicated push service account and the audience, acknowledgement deadline left at the default (the endpoint answers immediately), retry policy exponential backoff (VERIFY against current Pub/Sub defaults).
* The `GOOGLE_APPLICATION_CREDENTIALS` file must be readable only by `<SITE_USER>`:

```bash
# Run as: <SITE_USER>
mkdir -p ~/secrets && chmod 700 ~/secrets
# copy the key file here (scp from your laptop), then:
chmod 600 ~/secrets/gwl-amapi.json
```

Test from your laptop (flag on; works in pull mode too, because an empty push setting rejects every request): it must be **rejected, not hung and not 500**:

```bash
curl -i -X POST "https://<DOMAIN>/webhooks/android-management?token=wrong"     # expect 401 (the log line names the reason, e.g. bad_query_token or not_configured)
curl -i  "https://<DOMAIN>/webhooks/android-management"                         # expect 405 (POST only); 404 means the MDM flag is OFF or routes are cached from before it was turned on
```

### 11.2 Outbound access

The server needs outbound HTTPS to Google (`*.googleapis.com`, `accounts.google.com`) for MDM and to `sms.arkesel.com` for SMS, and outbound SMTP to your mail provider. A default UFW setup allows outbound traffic; if you restrict it, allow these.

### 11.3 Arkesel SMS

Details and limits: `docs/16-module-commercial-sms.md`. Server-side steps:

1. In the Arkesel dashboard get the **API key** and have your **sender ID(s)** approved (11 characters at most). Put them in `.env` (`GWL_ARKESEL_API_KEY`, `GWL_ARKESEL_SENDER_ID`).
2. **Allowed server IP:** if your Arkesel account restricts API calls by source IP, whitelist this server's public IP (`curl -s https://ifconfig.me`). VERIFY whether the account has such a setting.
3. **Delivery callback** (optional, VERIFY): `https://<DOMAIN>/webhooks/sms-arkesel?token=<GWL_ARKESEL_WEBHOOK_TOKEN>`. The endpoint exists, but the app does not yet send a `callback_url` per message and Arkesel's payload shape has never been verified, so rely on the scheduled `commercial:sms:poll-delivery` (every 10 minutes) for delivery status. Leave the token empty to keep the webhook off.
4. `GWL_ARKESEL_SANDBOX=true` first: nothing is delivered or billed.
5. Make sure the queue worker and the scheduler are running (section 9 and 10); campaigns are sent by queued chunks.

> **Never test the SMS gateway against production customers.** Test only in sandbox mode, or with one hand-picked number that belongs to the tester. Do not pick an audience group "just to see". A real send cannot be recalled, and the region lock (`GWL_COMMERCIAL_SMS_REGIONS`) only protects *which* customers, not *whether* they get a test message. Turning sandbox off is a business decision, not a deployment step.

### 11.4 Phase 11 checklist

- [ ] (MDM) Pull mode works first; push switched only after the webhook answers 401/405 as above.
- [ ] (MDM) The Google JSON file is outside `htdocs`, mode 600.
- [ ] (SMS) Sandbox is `true`, sender IDs approved, server IP whitelisted if required.
- [ ] No Basic Auth or IP block on the site while webhooks are in use.

---

## 12. Security hardening

### 12.1 Firewall (CloudPanel UI, then verify)

1. **Admin Area** -> **Security** (VERIFY the menu name) -> **Firewall**.
2. Keep only: **22** (SSH), **80**, **443**, **8443** (CloudPanel).
3. Edit the rules for **22** and **8443** to allow **only the administrators' IP addresses** if they have fixed IPs; otherwise rely on SSH keys and MFA below.
4. Never open **3306** (MySQL) or **6379** (Redis).

```bash
# Run as: root
ufw status verbose        # VERIFY: CloudPanel manages UFW; do not run ufw reset
ss -ltnp | grep -E ':3306|:6379'      # 3306 must show 127.0.0.1 only
```

### 12.2 SSH: keys only

```bash
# Run as: root   (first confirm in ANOTHER window that your key login works, or you will lock yourself out)
cat > /etc/ssh/sshd_config.d/10-erp-hardening.conf <<'EOF'
PasswordAuthentication no
PermitRootLogin prohibit-password
KbdInteractiveAuthentication no
MaxAuthTries 3
EOF
sshd -t && systemctl reload ssh
```

### 12.3 fail2ban

```bash
# Run as: root
apt -y install fail2ban
cat > /etc/fail2ban/jail.d/erp.local <<'EOF'
[DEFAULT]
bantime  = 1h
findtime = 10m
maxretry = 5
[sshd]
enabled = true
[recidive]
enabled = true
bantime = 1w
EOF
systemctl enable --now fail2ban && systemctl restart fail2ban
fail2ban-client status sshd
```

### 12.4 CloudPanel admin protection

1. CloudPanel UI -> your user (top right) / **Admin Area** -> **Users** -> edit the admin -> enable **Multi-Factor Authentication** and scan the QR code (VERIFY the exact menu). Store the recovery codes.
2. Use a personal admin account per person; do not share one login.

### 12.5 Nginx security headers and hidden files (CloudPanel UI)

Site -> **Vhost**, inside the `server { ... }` block add:

```nginx
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Strict-Transport-Security "max-age=31536000" always;      # only after HTTPS works on every address you use
```

No `Content-Security-Policy` is suggested: the app uses Alpine and inline scripts, and a wrong policy breaks pages (add one only after testing, starting with `Content-Security-Policy-Report-Only`).

Hidden files and non-public folders: the root is `public/`, so `.env`, `.git`, `vendor`, `storage` and `composer.json` are outside it. Prove it (from your laptop):

```bash
for p in .env .git/config composer.json storage/logs/laravel.log artisan vendor/autoload.php; do
  printf "%-26s" "$p"; curl -s -o /dev/null -w "%{http_code}\n" "https://<DOMAIN>/$p"
done          # every line must be 403 or 404, never 200
```

If any returns 200, stop and fix the vhost root (2.1 step 7) before going live. Also add (belt and braces) to the vhost:

```nginx
location ~ /\.(?!well-known) { deny all; }
```

### 12.6 App-level settings that are part of security

* `APP_DEBUG=false` and `APP_ENV=production` (stack traces and the demo-data guards).
* `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`.
* Logs: `LOG_STACK=daily` with `LOG_DAILY_DAYS=30`; the worker log is rotated by Supervisor (`stdout_logfile_maxbytes`); Nginx and PHP logs are rotated by the system's logrotate.
* The login already locks out after **5 failed attempts per staff ID + IP** (`LoginRequest`), password-reset links throttle at 6/min, and the webhooks and uploads have their own throttles.

### 12.7 Rate limiting of the login at Nginx (optional second layer)

```bash
# Run as: root   (VERIFY that /etc/nginx/conf.d/*.conf is included in the http context on your CloudPanel)
cat > /etc/nginx/conf.d/erp-ratelimit.conf <<'EOF'
map $request_method$uri $erp_login_key { default ""; "POST/login" $binary_remote_addr; }
limit_req_zone $erp_login_key zone=erp_login:10m rate=20r/m;
EOF
nginx -t
```

Then in the site's **Vhost**, inside `server { ... }` add `limit_req zone=erp_login burst=10 nodelay;` and save. An empty key (every other request) is not counted. Test: ten quick wrong logins from one IP should start returning 503.

### 12.8 Updates

```bash
# Run as: root
apt -y install unattended-upgrades
dpkg-reconfigure -plow unattended-upgrades          # answer Yes: security updates install themselves
systemctl list-timers | grep -i apt
```

Also schedule a monthly maintenance slot to update CloudPanel itself and reboot for kernel updates (VERIFY the current CloudPanel update procedure, `clp-update`).

### 12.9 Phase 12 checklist

- [ ] Only 22/80/443/8443 open; 22 and 8443 restricted to admin IPs where possible.
- [ ] SSH password login off, key login tested.
- [ ] fail2ban running; CloudPanel admin has MFA.
- [ ] Security headers saved; the `.env`/`.git` probe returns 403/404 for every line.
- [ ] MySQL listens on 127.0.0.1 only.
- [ ] `unattended-upgrades` enabled.

---

## 13. Backups and recovery

### 13.1 What and how often

| What | How | Frequency | Keep |
|---|---|---|---|
| Database dump (consistent, `--single-transaction`) | script in 13.2 | **daily at 01:30** (after the 00:20-00:30 leave jobs, before the 03:00 jobs) | 14 daily, 8 weekly, 12 monthly |
| `storage/app/private`, `storage/app/public` | CloudPanel backup or `rclone`/`restic` | daily (incremental) | same as above |
| `.env`, Google JSON, **APP_KEY** | copied by hand to the password manager / encrypted vault whenever they change | on change | all versions |
| Whole server snapshot | the cloud provider's snapshot feature | weekly | 4 |

Backups must go **off the server** (another provider or region). A backup that sits only on the same disk is not a backup.

### 13.2 Scheduled `mysqldump` (Run as: `<SITE_USER>`)

```bash
mkdir -p ~/bin ~/backups && chmod 700 ~/backups
cat > ~/.my.cnf <<'EOF'
[client]
user=<DB_USER>
password=<DB_PASSWORD>
host=127.0.0.1
EOF
chmod 600 ~/.my.cnf

cat > ~/bin/db-backup.sh <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
OUT=~/backups; DB=<DB>; STAMP=$(date +%F_%H%M)
mysqldump --single-transaction --quick --no-tablespaces --routines --triggers --hex-blob "$DB" | gzip -9 > "$OUT/$DB-$STAMP.sql.gz"
gzip -t "$OUT/$DB-$STAMP.sql.gz"                                  # the archive must be readable
find "$OUT" -name "$DB-*.sql.gz" -mtime +14 -delete               # local copies: 14 days
# Off-server copy (configure once with `rclone config`; VERIFY the remote name):
# rclone copy "$OUT/$DB-$STAMP.sql.gz" offsite:erp-db/ && rclone delete offsite:erp-db/ --min-age 90d
EOF
chmod 700 ~/bin/db-backup.sh && ~/bin/db-backup.sh && ls -lh ~/backups
```

Schedule it with CloudPanel **Cron Jobs** (as above): `30 1 * * *   /home/<SITE_USER>/bin/db-backup.sh >> /home/<SITE_USER>/logs/db-backup.log 2>&1`. Keep `~/backups` outside `htdocs`.

> **Warning: the Customer List database is large.** With millions of customers `mysqldump` takes a long time (expect a long run and a dump of many GB), holds a consistent snapshot open for its whole duration, and the restore is slower still (an hour or many hours; measure it, see 13.4). The staging tables are transient, but `commercial_customers` is **not** rebuildable (the uploaded workbooks are deleted once staged). If a dump takes longer than a few hours or the database exceeds roughly 20 GB, move to a physical backup (Percona XtraBackup or MySQL Enterprise-style hot backup, or a provider disk snapshot taken with `FLUSH TABLES WITH READ LOCK` / from a replica) and keep binary logs for point-in-time recovery. Decide this before the Customer List goes live, not after (VERIFY with the real size).

CloudPanel's own backup feature (Site -> **Backups**, with remote storage configured under **Admin Area** -> **Backups**; VERIFY the menus) can back up the site folder and be the off-server copy for `storage/`; do not rely on it alone for the database.

### 13.3 Restore procedure (write it down, then test it)

1. Put the app in maintenance mode: `<PHP> artisan down` (as `<SITE_USER>`) and stop the workers: `supervisorctl stop erp-worker:*` (as `root`).
2. **Never restore over the live database without taking a fresh dump first.**
3. Create an empty database (CloudPanel **Databases**, or restore into a *new* name first to test).
4. Load: `gunzip -c ~/backups/<file>.sql.gz | mysql <DB>` (as `<SITE_USER>` with `~/.my.cnf`).
5. Restore files: `storage/app/private`, `storage/app/public` from the file backup; put `.env` (with the **original** `APP_KEY`) back.
6. `<PHP> artisan migrate:status | grep -c Pending` (if the dump is older than the code, `migrate --force` brings it forward), `<PHP> artisan optimize`.
7. `supervisorctl start erp-worker:*`, `<PHP> artisan up`.
8. Run the smoke test (15.3). Record how long each step took.

### 13.4 Test the restore

Once before go-live and then every quarter: restore the latest dump and files **on a scratch server or a differently named database**, open the app against it, log in, open a PV attachment and a leave letter with a signature, and write the elapsed time in the runbook. An untested backup is a hope.

### 13.5 Phase 13 checklist

- [ ] `db-backup.sh` runs from cron and the dump is readable (`gzip -t`).
- [ ] Off-server copy of the dumps and of `storage/app/*` exists.
- [ ] `.env`, Google JSON and `APP_KEY` are in the password manager.
- [ ] A restore was tested and its duration written down.
- [ ] Decision recorded for large-database backups (dump vs physical).

---

## 14. Deployment procedure for later releases

### 14.1 The rules

* Deploy a **tag** (`v1.4.0`) from `main`; the server's working copy must be clean (`git status` empty).
* **Migrations are never edited once merged.** A mistake is fixed with a **new** migration. Production never runs `migrate:rollback`, `migrate:fresh` or `db:wipe`.
* Do not deploy while a Customer List or Detailed Billing import is running (look at the batch pages and `SELECT COUNT(*) FROM jobs`). Maintenance mode also pauses the workers.
* Do **not** run `cache:clear` or `optimize:clear`: they delete the cache table, including the district locks of a running import, the `queue:restart` signal and the scheduler's overlap locks. `php artisan optimize` rebuilds the config, route, view and event caches without touching the data cache.

### 14.2 One-time: let the deploy user reload PHP-FPM

PHP-FPM must be reloaded so OPcache (section 16) sees the new code. The site user cannot do that, so allow exactly that command (Run as: `root`; VERIFY the service name with `systemctl list-units | grep php`):

```bash
echo '<SITE_USER> ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.5-fpm' > /etc/sudoers.d/erp-deploy
chmod 440 /etc/sudoers.d/erp-deploy && visudo -cf /etc/sudoers.d/erp-deploy
```

### 14.3 `~/bin/deploy.sh` (Run as: `<SITE_USER>`)

Usage: `~/bin/deploy.sh v1.4.0`. It is also kept in this guide because the repository has no deploy script yet (Problems found, item 8); ask the developers to commit it as `deploy/deploy.sh`.

```bash
#!/usr/bin/env bash
# Deploy a tagged release. Usage: deploy.sh <tag-or-ref>
set -Eeuo pipefail

APP_DIR="/home/<SITE_USER>/htdocs/<DOMAIN>"
PHP="<PHP>"
REF="${1:?usage: deploy.sh <tag-or-ref>}"
COMPOSER="$(command -v composer)"

cd "$APP_DIR"
[ -z "$(git status --porcelain)" ] || { echo "Working copy is not clean; stop."; exit 1; }
PREVIOUS="$(git rev-parse HEAD)"
echo "$PREVIOUS" > "$HOME/last-good-commit"
echo "Deploying $REF over $PREVIOUS"

trap 'echo; echo "FAILED. The site is in maintenance mode. Read the error above, then either fix and re-run, or roll back: deploy.sh $PREVIOUS"; exit 1' ERR

$PHP artisan down --retry=60 --refresh=15

git fetch --tags --prune origin
git checkout --detach "$REF"

COMPOSER_MEMORY_LIMIT=-1 $PHP "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction

source "$HOME/.nvm/nvm.sh"
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

# Pending migrations? Then a fresh DB backup first (skip only when the last daily dump is fresh and the migrations are additive).
if ! $PHP artisan migrate:status --pending 2>&1 | grep -qi 'no pending migrations'; then
  "$HOME/bin/db-backup.sh"       # VERIFY the wording of "No pending migrations" on this Laravel version
fi
$PHP artisan migrate --force

$PHP artisan storage:link 2>/dev/null || true
$PHP artisan optimize                      # config:cache + route:cache + view:cache + event:cache
$PHP artisan queue:restart                 # workers finish their job, then Supervisor starts fresh ones
sudo /usr/bin/systemctl reload php8.5-fpm  # clears OPcache so the new code is used

$PHP artisan up
curl -fsS "https://<DOMAIN>/up" > /dev/null && echo "Health check OK" || { echo "Health check FAILED"; exit 1; }
echo "Deployed $(git describe --tags --always)"
```

Good result: ends with `Health check OK`. Then run the quick smoke test (15.3).

### 14.4 Rollback plan

| Situation | Action |
|---|---|
| Code is bad, **no migration ran** | `~/bin/deploy.sh $(cat ~/last-good-commit)` (it redeploys the previous commit through the same steps) |
| Code is bad, **migrations ran and were additive** (new tables/columns) | redeploy the previous commit as above; the old code ignores the new columns. Fix forward with a **new** migration/commit |
| A migration damaged or lost data | `artisan down`, stop the workers, **restore the pre-deploy dump** taken by the script (13.3), redeploy the previous commit, `artisan up`. Do **not** run `migrate:rollback` (not every `down()` is safe, and it can drop data) |
| `.env` change broke the site | restore the previous `.env`, `artisan config:cache`, `queue:restart`, reload PHP-FPM |

### 14.5 Phase 14 checklist

- [ ] `~/bin/deploy.sh` exists, is executable, and the sudoers rule works (`sudo -n systemctl reload php8.5-fpm`).
- [ ] The first scripted deploy of a tag succeeded and ended with `Health check OK`.
- [ ] The rollback row was read by everyone who deploys.

---

## 15. Post-deploy verification

### 15.1 Checklist

- [ ] `https://<DOMAIN>/up` returns 200 (Laravel's built-in health route; use it for uptime monitoring).
- [ ] Login with the super admin works and the password-change page appears (7.4).
- [ ] **Queue:** the test job of 9.4 wrote its log line; `supervisorctl status` shows 2 RUNNING.
- [ ] **Scheduler:** `schedule:list` is as expected and the cron line exists (10.1).
- [ ] **Mail:** send a test message (below) and receive it.
- [ ] **Excel import:** the Staff import preview accepts a small `.xlsx` (UAC > Users import or Staff > Import).
- [ ] **Excel/PDF export:** download one Excel export and one PDF (for example the Visitors log, or a Transport report).
- [ ] **Private files:** an anonymous request for a blog picture or waybill route redirects to login, and `/storage/` has no listing (8.5).
- [ ] **MDM webhook** (only if enabled): the two `curl` tests of 11.1 behave as described.
- [ ] `storage/logs/laravel-<today>.log` has no new `ERROR` lines.

```bash
# Run as: <SITE_USER>   - mail test (replace the address)
cd <APP_DIR>
<PHP> artisan tinker --execute='Mail::raw("ERP production mail test", fn ($m) => $m->to("you@your-domain")->subject("ERP mail test")); echo "sent\n";'
```

### 15.2 Quick smoke test per module (5 minutes each, with a real but low-privilege account)

| Module | Test |
|---|---|
| UAC | open Users; invite a test user (check the invite e-mail arrives); open the audit log |
| Staff | open the directory, filter by region; Staff Reports page draws its charts |
| Leave | open Apply, upload a small attachment, submit; a manager sees it in Approvals (e-mail + bell); open a leave letter PDF |
| Letters | create a letter, dispatch it, confirm the recipient's bell notice |
| Visitors | `/kiosk` loads, check a visitor in and out |
| Assets | open the dashboard and an asset record; Assets export downloads |
| MDM (flag on) | MDM dashboard "Event intake" tile shows PULL or PUSH and a recent event time |
| Transport | open Vehicles for your region only (another region's vehicle answers 404); run `art transport:check-expiries` |
| Commercial (flag on) | upload a **small synthetic** report on Uploads, watch the batch become `imported`; for the Customer List upload a tiny test file, confirm the worker processes it, then void it |
| Health & Safety (flag on) | file a test incident, open the register; `art health-safety:send-alerts --dry-run` runs |
| Blog | open the Blog link (new tab), write a draft with a picture; the picture URL is `/blog/media/...` |
| G-Water (flag on) | record a test price/sale in a test location and void it with a reason |
| Finance / Audit (flag on) | prepare a PV, send it, and check Audit sees it only after assignment |
| SMS (flag on, **sandbox**) | build an audience, see the cost estimate, stop before confirming unless sandbox is `true` |

### 15.3 Logs to watch

| Log | Where | What it tells you |
|---|---|---|
| Application | `<APP_DIR>/storage/logs/laravel-YYYY-MM-DD.log` | exceptions, failed mail, webhook rejections (with the reason code) |
| Worker | `/home/<SITE_USER>/logs/erp-worker.log` | jobs picked up, failed, timed out |
| Nginx | CloudPanel site -> **Logs** (files under `/home/<SITE_USER>/logs/nginx/` , VERIFY) | 4xx/5xx, 413 (body too large), 502/504 |
| PHP-FPM | CloudPanel site -> **Logs** (`/home/<SITE_USER>/logs/php/`, VERIFY) | fatal errors, memory limit |
| MySQL | `/var/log/mysql/error.log` | crashes, slow-query log if enabled (16.3) |
| Cron / scheduler | `journalctl -u cron` or `/var/log/syslog` | whether `schedule:run` fires |
| Supervisor | `/var/log/supervisor/supervisord.log` | worker restarts |
| SSH / bans | `/var/log/auth.log`, `fail2ban-client status` | intrusion attempts |

(`php artisan pail` is a developer tool and is not installed with `--no-dev`; use `tail -f`.)

---

## 16. Performance tuning

### 16.1 OPcache (Run as: `root`)

```bash
cat > /etc/php/8.5/fpm/conf.d/99-erp-opcache.ini <<'EOF'
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=30000
opcache.validate_timestamps=0     ; code changes only after a PHP-FPM reload: the deploy script does that
opcache.save_comments=1           ; required (Livewire/Laravel read docblock attributes)
EOF
systemctl reload php8.5-fpm
```

If someone edits code by hand on the server it will *not* show until the next reload (`validate_timestamps=0`); nobody should be editing code there. (VERIFY that CloudPanel does not override these values per site.) Leave the JIT at its default.

### 16.2 PHP-FPM pool (CloudPanel UI)

Site -> **Settings** -> **PHP Settings** -> process manager fields (VERIFY the names): `pm = dynamic`.

Rule of thumb: `pm.max_children = (RAM left for PHP) / 150 MB`.

| Server | `pm.max_children` | `pm.start_servers` | `pm.min_spare_servers` | `pm.max_spare_servers` | `pm.max_requests` |
|---|---|---|---|---|---|
| 8 GB | 12 | 3 | 2 | 6 | 500 |
| 32 GB | 50 | 8 | 4 | 12 | 500 |

Keep MySQL `max_connections` above `max_children + workers + cron + spare` (200 is plenty).

### 16.3 MySQL for the Customer List (Run as: `root`)

The Customer List benchmark's one open question is the **buffer pool** (the laptop had 128 MB while the data was several GB). Give it real memory.

```bash
cat > /etc/mysql/conf.d/zz-erp.cnf <<'EOF'
[mysqld]
bind-address                 = 127.0.0.1
skip-name-resolve
innodb_buffer_pool_size      = 16G        # about 50% of RAM on the 32 GB server; 3G on the 8 GB server
innodb_redo_log_capacity     = 4G         # MySQL 8.4 (older: innodb_log_file_size); bulk merges write a lot
innodb_flush_method          = O_DIRECT
innodb_file_per_table        = 1
max_connections              = 200
max_allowed_packet           = 64M
tmp_table_size               = 256M
max_heap_table_size          = 256M
binlog_expire_logs_seconds   = 259200     # 3 days: bulk loads create huge binary logs; the default is 30 days of them
local_infile                 = 0
slow_query_log               = 1
slow_query_log_file          = /var/log/mysql/slow.log
long_query_time              = 2
EOF
systemctl restart mysql
mysql -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';"
```

(VERIFY the file location CloudPanel's MySQL reads, `mysqld --verbose --help | grep -A1 'Default options'`, and the variable names for the installed version.) After the **first** large Customer List load, once:

```bash
mysql <DB> -e "ANALYZE TABLE commercial_customers, commercial_customer_contacts;"
```

Watch disk usage during the first imports (`df -h`, `du -sh /var/lib/mysql`): indexes and binary logs grow first.

### 16.4 Redis (optional)

Do this only if measured need appears (many concurrent users, or cache/session load on MySQL). It is **not** needed for go-live.

```bash
# Run as: root
apt -y install redis-server php8.5-redis
# /etc/redis/redis.conf:  bind 127.0.0.1 -::1 ; requirepass <strong password> ; appendonly yes
systemctl restart redis-server && systemctl restart php8.5-fpm && supervisorctl restart erp-worker:*
```

`.env`: `REDIS_CLIENT=phpredis`, `REDIS_HOST=127.0.0.1`, `REDIS_PASSWORD=<secret>`, then `CACHE_STORE=redis` and `SESSION_DRIVER=redis`. Move the **queue** to Redis only if you accept the trade-off (jobs live in memory/AOF instead of MySQL) and set `REDIS_QUEUE_RETRY_AFTER=7500` (the `DB_QUEUE_RETRY_AFTER` rule applies to it). The `config/cache.php` Redis store uses the `cache` connection and locks the `default` one, both defined in `config/database.php`: VERIFY they connect before switching. Run `config:cache` and `queue:restart` afterwards.

### 16.5 Nginx: compression and static assets (CloudPanel UI)

Vite writes content-hashed files to `/build/`, so they can be cached for a year. In the site **Vhost**, add inside `server { ... }`, **above** the PHP location:

```nginx
location ^~ /build/ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    access_log off;
    try_files $uri =404;
}
gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types text/plain text/css application/json application/javascript text/javascript image/svg+xml application/xml;
```

(VERIFY that CloudPanel's global Nginx does not already enable gzip; a duplicate directive makes `nginx -t` fail, in which case drop the `gzip*` lines.) Test: `curl -sI -H 'Accept-Encoding: gzip' https://<DOMAIN>/build/manifest.json | grep -i 'content-encoding\|cache-control'`.

### 16.6 Phase 16 checklist

- [ ] OPcache file in place; the deploy script reloads PHP-FPM.
- [ ] `pm.max_children` set for the server size.
- [ ] MySQL buffer pool set and confirmed with `SHOW VARIABLES`.
- [ ] `ANALYZE TABLE` run after the first large Customer List load.
- [ ] `/build/` assets return a long `Cache-Control`.

---

## 17. Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| **500 Server Error** on every page | `.env` missing/unreadable, no `APP_KEY`, DB credentials wrong, `storage` not writable, stale config | `tail -50 storage/logs/laravel-*.log`; check `.env` mode/owner (8.1); `art config:clear && art config:cache` |
| **Blank white page** | PHP fatal with `display_errors=Off`, or the Vite build is missing | read the PHP-FPM log and `laravel.log`; check `public/build/manifest.json`; reload PHP-FPM (stale OPcache) |
| **"Vite manifest not found"** / unstyled pages | `npm run build` was never run on this server (`public/build` is git-ignored) | run 5.4 again; `ls public/build/manifest.json` |
| **419 Page Expired** on login or Livewire actions | `APP_URL` is not the real address, `SESSION_SECURE_COOKIE=true` while the site is opened over http, wrong `SESSION_DOMAIN`, a proxy changes scheme/IP, stale cached config | open the site only via `https://<DOMAIN>`; fix `.env`; `config:cache`; clear browser cookies; if a proxy/CDN is in front see 2.3 (a code change is needed) |
| **403/404 on an image or file** | Nginx root wrong, `storage:link` missing (only for the 2 public-disk features), or a scope rule (a 404 is intentional for another region's record) | `ls -l public/storage`; check the roles/region before treating it as a bug |
| **Permission denied** writing to `storage` or `bootstrap/cache` | something ran as root, or wrong owner | as root `chown -R <SITE_USER>:<SITE_USER> <APP_DIR>/storage <APP_DIR>/bootstrap/cache`; then 8.1 |
| **Jobs not processing** (batch stays "queued", SMS never goes) | no worker, worker crashed, site in maintenance mode, wrong PHP path, config cached before `.env` change | `supervisorctl status`; the worker log; `artisan up`; `queue:failed`; `queue:restart` |
| **Same import started twice / "district busy" loops** | `DB_QUEUE_RETRY_AFTER` left at 90 s | set `DB_QUEUE_RETRY_AFTER=7500`, `config:cache`, `supervisorctl restart erp-worker:*`, then check the batch pages |
| **Import stuck "processing" after a crash** | the district lock (4 h) of a killed worker | wait for it to expire, or ask a developer; do **not** `cache:clear` |
| **Scheduler not firing** | cron line missing or wrong PHP path/user, the module flag off (command not scheduled), overlap lock stuck | `crontab -l`, `schedule:list`, `schedule:run -v`, `schedule:clear-cache`, `journalctl -u cron` |
| **Upload fails at the size limit** (413, or "failed to upload", or the page just reloads) | Nginx `client_max_body_size`, PHP `upload_max_filesize` / `post_max_size`, or the app's `GWL_*_MAX_MB` is the smaller of the three | raise all three consistently (section 3); a 413 in the Nginx error log is the web server |
| **Memory exhausted on an Excel import/export** (web) | web `memory_limit` too low for PhpSpreadsheet (the smaller reports and Staff/UAC imports are read whole; a 5000-row export is memory hungry) | raise the site `memory_limit`; narrow the filters; a Customer List / Detailed Billing file is **streamed in the worker** and does not need more |
| **Memory exhausted inside the worker** | the CLI `memory_limit` (128 MB) was used | the Supervisor command must contain `-d memory_limit=1024M` (9.3) |
| **Mail not arriving** | `MAIL_MAILER` still `log`, wrong port/scheme, provider blocks the server IP | check `.env`; `tail storage/logs/*.log` for the transport error; send the 15.1 test; ask the provider about SPF/DKIM |
| **Webhook 401/404/503** | wrong token or audience (the log names the reason), flag off or routes cached before the flag, Basic Auth on the site, maintenance mode | 11.1 tests; `config:cache` + `route:cache` after a flag change |
| **Wrong times** | the app is fixed to UTC (`config/app.php`) | nothing to fix in Ghana (UTC+0); for another zone this needs a code change |
| **Someone ran a demo command in production** | `finance:demo-data` and `letters:demo-data` **refuse to run when `APP_ENV=production`** (and the Commercial `*:sample` commands are documented to refuse too, VERIFY each), but `DemoDataSeeder` has no such guard, and every guard fails open if `APP_ENV` is wrong | **`php artisan finance:demo-data` must never be run in production.** Keep `APP_ENV=production`, never run `db:seed --class=DemoDataSeeder`. If demo rows exist: stop, take a dump, and have a developer remove them (the finance demo command has a `--remove` that deletes only its own `DEMO-` rows, but it must be run by someone who has read `docs/20-module-finance-audit.md`); do not delete by hand |

---

## Problems found

Gaps in the repository that affect deployment. **None of these was fixed**; they need a decision from the developers.

1. **`SuperAdminSeeder` ships a known login** (`staff_id 21475`, `Admin@12`, `superadmin@ml.local`) and does not set `must_change_password`. Anyone with the repository can log in as super admin on a fresh deploy until 7.4 is done. Suggest: read the password from an env/prompt or generate one and set `must_change_password = true`.
2. **`DB_QUEUE_RETRY_AFTER` is not in `.env.example`** and `config/queue.php` defaults it to **90 s**, while the import jobs run up to 7200 s (the job docblocks say it must be longer). A missed setting gives duplicate import runs. Add it to `.env.example` with a note.
3. **`.env.example` is incomplete and misleading for production**: it has no `GWL_FINANCE_AUDIT_ENABLED` (Finance and Audit stay off by default and nobody can find the switch), and no `GWL_FIN_*`, `GWL_AUDIT_REMIND_*`, `GWL_ASSETS_*`, `GWL_SIGNATURE_*`, `GWL_MDM_*_RATE/RETENTION`, `GWL_COMMERCIAL_SMS_WEBHOOK_RATE_PER_MINUTE`, `GWL_AUTO_CHECKOUT_TIME`, `GWL_CARRY_OVER_EXPIRY_DAYS`, `GWL_LEAVE_NOTIFICATION_POLL_SECONDS`, `GWL_VISITOR_KIOSK_RESET_SECONDS`, `GWL_MAX_IMPORT_FAILURE_PERCENT`, and no `SESSION_SECURE_COOKIE`. Two comment lines are garbled (the "PLACEHOLDER targets # --- Health & Safety module" line and the "# --- Commercial module (buntil the Commercial team..." line). It defaults to `APP_ENV=local`, `APP_DEBUG=true`, `DB_CONNECTION=sqlite`, `LOG_LEVEL=debug`, `LOG_STACK=single`, `APP_NAME=Laravel`. A `.env.production.example` would remove the guesswork.
4. **No trusted-proxy configuration** (`bootstrap/app.php` has no `trustProxies`). Fine for Nginx -> PHP-FPM on the same host; broken behind Cloudflare, a load balancer or Varnish (wrong client IP for the login lock-out and webhook rate limits, scheme detection). A code change is needed before any such proxy.
5. **Two features use the public disk**: leave supporting documents (`storage/app/public/leave_attachments`, `ApplyForm`) and asset model pictures. Leave documents are openly downloadable by URL, unlike everything else in the app (private `local` disk behind scope-checked routes), and require `storage:link`. Suggest moving leave attachments to the private disk behind an authorised route.
6. **No queue separation**: all jobs use the `default` queue and one `retry_after`. A multi-hour import and a one-second MDM or SMS job share it; the only mitigation here is running two workers and a large `retry_after` (which also delays recovery of any killed job by about 2 hours). Suggest `onQueue('imports')` plus a second connection with its own `retry_after`.
7. **Worker documentation disagrees**: `docs/assets/mdm.md` shows `queue:work --tries=3 --timeout=90`, `docs/15-...` says `--timeout=7300`, `docs/commercial-module-design.md` says `--timeout=7200 --memory=256`. This guide uses 7300/768 MB; the other docs should point here.
8. **No deployment assets in the repository**: no deploy script, no Supervisor file, no cron entry, no production `.env`. `docker-compose.yml` at the root is unrelated to this layout (an image named `laravel-master`, a 256 MB limit, a `/var/www/copilot-infra/ultrahorizon99` path) and looks like a leftover from another project: it should be removed or documented.
9. **`DemoDataSeeder` has no production guard** (the finance/letters demo commands and the sample commands do). One mistyped `db:seed --class=DemoDataSeeder` would insert invented staff and records into a live database.
10. **`config/app.php` hard-codes `'timezone' => 'UTC'`** (no `APP_TIMEZONE`). Harmless in Ghana (UTC+0), but schedule times and stored times cannot follow a different zone without a code change.
11. **Test artefacts are tracked in git**: `storage/framework/testing/disks/...` (G-Water waybills, scanned letter PDFs) and `storage/framework/lsp-*.php` are committed. A production clone would contain them; they should be removed from git and ignored.
12. **A fresh `migrate` on empty MySQL has not been proven end to end** in the project notes (several migrations were only run on SQLite/dev; one earlier MySQL-only foreign-key bug is documented in `CLAUDE.md`). Step 7.2 is the safeguard; consider making it a CI job on a MySQL service container.
13. **SMS delivery callback is unverified**: Arkesel's webhook payload shape and per-message status API have never been checked against a real account, and the app does not send a `callback_url` yet (`docs/16-module-commercial-sms.md`). The poll is the only delivery source until then.
14. **Customer List at 5 to 10 million rows is not proven**: the 5M benchmark missed its targets on a 128 MB buffer pool and was never repeated on production-like settings (`docs/15-module-commercial-customer-list.md`). The sizing in section 1.2 is an estimate until it is.
15. **The code to deploy is not committed**: the working branch is `feat/assets-mdm` with many uncommitted changes. Production must deploy a reviewed, tagged commit on `main`.

---

## VERIFY list

Everything in this guide that could not be confirmed from the repository and must be checked on the real server or vendor page:

1. CloudPanel installer command, checksum and the exact `DB_ENGINE` value (`MYSQL_8.4`); supported Ubuntu versions (1.1, 1.4).
2. Whether the CloudPanel version offers **PHP 8.5** and the name of the Laravel site template (2.1).
3. That `http://` redirects to `https://` on the CloudPanel vhost (2.2).
4. The PHP binary path `/usr/bin/php8.5`, the Composer path, and the Ondrej package names for missing extensions (3.1, 5.3).
5. The CloudPanel menu names: **PHP Settings**, **Vhost**, **Cron Jobs**, **Security**, **Logs**, **Backups**, MFA location, process-manager fields (2, 3, 10, 12, 13, 16).
6. The default `client_max_body_size` in CloudPanel's vhost template, and that `/etc/nginx/conf.d/*.conf` is loaded in the `http` context (3.3, 12.7); whether global gzip is already on (16.5).
7. Current nvm version URL (5.4).
9. MySQL config file location and variable names for the installed MySQL (4.3, 16.3); collation actually created by CloudPanel (4.3); MariaDB acceptance if chosen (4.1).
10. Mail provider port/scheme combination (`MAIL_SCHEME`) (6.2).
11. Whether UAC lets you rename/deactivate the seeded staff ID 21475 (7.4).
12. CloudPanel's per-site log folders (`/home/<SITE_USER>/logs/...`) (9.3, 15.3); cron log name on Ubuntu 24.04 (10.1).
13. Pub/Sub retry policy defaults, and Arkesel's source-IP restriction and callback support (11.1, 11.3).
14. The `systemctl` service name `php8.5-fpm` (14.2, 16.1); whether CloudPanel overrides the OPcache values (16.1).
15. Redis cache/lock connections in `config/database.php` before switching to Redis (16.4).
16. The 5 to 10 million customer sizing and backup method, by benchmark on the real server (1.2, 13.2).
17. Current CloudPanel update procedure (`clp-update`) (12.8).
