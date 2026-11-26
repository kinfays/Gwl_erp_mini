<?php

return [
    'auto_checkout_time' => env('GWL_AUTO_CHECKOUT_TIME', env('GWCL_VISITORS_AUTO_CHECKOUT_TIME', '18:00')),
    'carry_over_expiry_days' => (int) env('GWL_CARRY_OVER_EXPIRY_DAYS', 90),
    'leave_notification_poll_seconds' => (int) env('GWL_LEAVE_NOTIFICATION_POLL_SECONDS', 90),
    // Master switch for every leave email (submitted / recommended / approved / denied / HR). In-app
    // notifications are never affected. LeaveNotificationService is the only place that checks it.
    'leave_email_notifications_enabled' => (bool) env('GWL_LEAVE_EMAIL_NOTIFICATIONS_ENABLED', true),
    // The email to HR after a final approval. Defaults to the master switch; it can turn HR emails off on
    // its own, but never on while the master switch is off.
    'leave_hr_email_notifications_enabled' => (bool) env(
        'GWL_LEAVE_HR_EMAIL_NOTIFICATIONS_ENABLED',
        env('GWL_LEAVE_EMAIL_NOTIFICATIONS_ENABLED', true)
    ),

    // Annual leave by grade (App\Enums\StaffGrade), worked out by LeaveEntitlementCalculator. Tenure is the whole years
    // between the hire date (employees.date_joined) and 1 January of the leave year.
    'leave_annual_days' => [
        // Junior Gd. Levels 1-3: fewer days until they have served `leave_junior_lower_tenure_years` years.
        'junior_lower_short_tenure' => (int) env('GWL_LEAVE_DAYS_JUNIOR_LOWER_SHORT', 26),
        'junior_lower_long_tenure' => (int) env('GWL_LEAVE_DAYS_JUNIOR_LOWER_LONG', 31),
        // Junior Gd. Levels 4-7.
        'junior_upper' => (int) env('GWL_LEAVE_DAYS_JUNIOR_UPPER', 31),
        // Snr. Gd. and Mgt. Gd., every level.
        'senior_and_management' => (int) env('GWL_LEAVE_DAYS_SENIOR_MANAGEMENT', 36),
        // Staff with no grade yet keep the flat entitlement they had before grades existed.
        'ungraded' => (int) env('GWL_LEAVE_DAYS_UNGRADED', 31),
    ],
    'leave_junior_lower_tenure_years' => (int) env('GWL_LEAVE_JUNIOR_LOWER_TENURE_YEARS', 10),

    // Compulsory leave: the days taken off gross entitlement for a year with no compulsory_leave_periods record, and
    // the staff it applies to (employees.location_type). District staff and contract staff are exempt.
    'leave_compulsory_default_days' => (int) env('GWL_LEAVE_COMPULSORY_DEFAULT_DAYS', 11),
    'leave_compulsory_location_types' => ['HeadOffice', 'Region'],

    // Retirement: the age (Employee::retirementAge()) and how far ahead the HR analytics looks for people approaching it.
    'retirement_age' => (int) env('GWL_RETIREMENT_AGE', 60),
    'retirement_window_months' => (int) env('GWL_RETIREMENT_WINDOW_MONTHS', 12),

    // HR analytics (App\Services\Hr\HrAnalyticsService): how long a computed dashboard is reused. 0 turns the cache off.
    'hr_analytics_cache_seconds' => (int) env('GWL_HR_ANALYTICS_CACHE_SECONDS', 120),

    // Leave approval letters: the private disk saved signatures are kept on (config/filesystems.php), the biggest upload
    // accepted (KB) and the size a signature is scaled down to (pixels) before it is stored.
    'signature_disk' => env('GWL_SIGNATURE_DISK', 'leave_signatures'),
    'signature_max_upload_kb' => (int) env('GWL_SIGNATURE_MAX_UPLOAD_KB', 1024),
    'signature_max_width' => 600,
    'signature_max_height' => 200,

    'visitor_kiosk_reset_seconds' => (int) env('GWL_VISITOR_KIOSK_RESET_SECONDS', 5),
    'max_import_failure_percent' => (int) env('GWL_MAX_IMPORT_FAILURE_PERCENT', 20),

    // Health & Safety (incident reporting now; equipment and PPE later): off by default; when off the routes don't register
    // and the module is hidden from navigation (docs/health-safety-module-design.md).
    'health_safety_module_enabled' => (bool) env('GWL_HEALTH_SAFETY_MODULE_ENABLED', false),

    // Regional Blog (PR articles about activities, meetings, workshops and training, read by the staff of one region): on by
    // default; when off the routes don't register and the module is hidden from navigation (docs/14-module-regional-blog.md).
    'blog_module_enabled' => (bool) env('GWL_BLOG_MODULE_ENABLED', true),
    // Biggest cover photo accepted (MB). Keep at or below PHP upload_max_filesize / post_max_size.
    'blog_cover_max_mb' => (int) env('GWL_BLOG_COVER_MAX_MB', 3),
    // Rich articles and pictures (docs/14-module-regional-blog.md). Every picture is checked by content, re-encoded and stored
    // on the private disk; these are the limits. Keep blog_media_max_mb at or below PHP upload_max_filesize / post_max_size.
    'blog_media_max_mb' => (int) env('GWL_BLOG_MEDIA_MAX_MB', 8),
    'blog_media_max_dimension' => (int) env('GWL_BLOG_MEDIA_MAX_DIMENSION', 6000),
    'blog_media_max_megapixels' => (int) env('GWL_BLOG_MEDIA_MAX_MEGAPIXELS', 24),
    'blog_media_max_per_article' => (int) env('GWL_BLOG_MEDIA_MAX_PER_ARTICLE', 60),
    // A picture uploaded but never used in an article is deleted by blog:prune-media after this many hours.
    'blog_media_orphan_hours' => (int) env('GWL_BLOG_MEDIA_ORPHAN_HOURS', 24),
    // Saved versions kept per article, and how often (seconds) the editor saves a DRAFT by itself.
    'blog_revisions_keep' => (int) env('GWL_BLOG_REVISIONS_KEEP', 20),
    'blog_autosave_seconds' => (int) env('GWL_BLOG_AUTOSAVE_SECONDS', 10),
    // Unread-articles badge on the header's Blog link: only articles first published within this many days count (so a new
    // user is not shown the whole archive), and the number is cached per user for this many seconds (it is read on every page).
    'blog_unread_lookback_days' => (int) env('GWL_BLOG_UNREAD_LOOKBACK_DAYS', 30),
    'blog_unread_cache_seconds' => (int) env('GWL_BLOG_UNREAD_CACHE_SECONDS', 60),
    // Login announcements: the most shown in the window after a login, and how many hours must pass before a remember-me
    // re-authentication (no password typed, a new browser session) may show them again. 0 = only the login form shows them.
    'blog_login_announcements_max' => (int) env('GWL_BLOG_LOGIN_ANNOUNCEMENTS_MAX', 3),
    'blog_login_announcements_recall_hours' => (int) env('GWL_BLOG_LOGIN_ANNOUNCEMENTS_RECALL_HOURS', 12),
    // Likes and views: a person's repeat opens of one article within this many minutes count as ONE view (0 = every open counts;
    // the author, a manager of the region and a prefetch never count either way). The throttle lives in the cache, not the database.
    'blog_view_throttle_minutes' => (int) env('GWL_BLOG_VIEW_THROTTLE_MINUTES', 30),

    // G-Water (bottled and sachet water bought from G Water and sold by each location: prices, trips, daily sales, stock,
    // reimbursements): off by default; when off the routes don't register and the module is hidden from navigation
    // (docs/17-module-g-water.md).
    'gwater_module_enabled' => (bool) env('GWL_GWATER_MODULE_ENABLED', false),
    // Rows allowed in one G-Water Excel / PDF export (the PDF is far heavier to build, so it has its own, lower cap).
    'gwater_export_max_rows' => (int) env('GWL_GWATER_EXPORT_MAX_ROWS', 5000),
    'gwater_export_pdf_max_rows' => (int) env('GWL_GWATER_EXPORT_PDF_MAX_ROWS', 1500),
    // Overview flags: packs on hand at or below this are "low" (a location may set its own per product), and a damage rate
    // (damaged / received) above this percentage is flagged.
    'gwater_low_stock_packs' => (int) env('GWL_GWATER_LOW_STOCK_PACKS', 50),
    'gwater_damage_rate_flag_pct' => (float) env('GWL_GWATER_DAMAGE_RATE_FLAG_PCT', 5),
    // Reminders (php artisan gwater:remind, daily, needs the scheduler): low stock is reminded about at most once per repeat
    // interval. Each location may set its own.
    'gwater_reminder_repeat_days' => (int) env('GWL_GWATER_REMINDER_REPEAT_DAYS', 7),
    // Biggest waybill picture accepted when a trip is recorded (MB). Keep at or below PHP upload_max_filesize / post_max_size.
    'gwater_waybill_photo_max_mb' => (int) env('GWL_GWATER_WAYBILL_PHOTO_MAX_MB', 5),
    // Most rows (all sheets together) one past-ledger import file may hold; the file is read into memory.
    'gwater_import_max_rows' => (int) env('GWL_GWATER_IMPORT_MAX_ROWS', 2000),
    // Finance and Audit (payment vouchers prepared by Finance, acknowledged by Audit; docs/20-module-finance-audit.md): ONE flag
    // for both modules. Off by default; when off the routes don't register and both modules are hidden from navigation.
    'finance_audit_enabled' => (bool) env('GWL_FINANCE_AUDIT_ENABLED', false),
    // Sanity ceiling for one payment voucher, in GH¢ (a typing mistake such as an extra zero is refused, not paid).
    'fin_amount_max_cedis' => (int) env('GWL_FIN_AMOUNT_MAX_CEDIS', 50000000),
    // Supporting documents on a voucher (PV scan, receipts): biggest file in MB and most files per voucher.
    'fin_attachment_max_mb' => (int) env('GWL_FIN_ATTACHMENT_MAX_MB', 5),
    'fin_attachment_max_per_pv' => (int) env('GWL_FIN_ATTACHMENT_MAX_PER_PV', 10),
    // Most rows one supplier import file may hold (the file is read into memory).
    'fin_supplier_import_max_rows' => (int) env('GWL_FIN_SUPPLIER_IMPORT_MAX_ROWS', 2000),
    // Most vouchers one batch may hold (a batch is a container for sending, assigning and printing, never a unit of approval).
    'fin_batch_max_vouchers' => (int) env('GWL_FIN_BATCH_MAX_VOUCHERS', 100),
    // Most vouchers one printed PDF may hold (rows of a batch table, or voucher pages when each is on its own page).
    'fin_print_max_vouchers' => (int) env('GWL_FIN_PRINT_MAX_VOUCHERS', 200),
    // Reminders: TWO independent sets, each changed only by its own manager and per location (Finance > Settings > Reminders needs
    // finance.manage_reminders; Audit > Settings > Reminders needs audit.manage_reminders). A voucher that has waited longer than
    // the threshold is reminded about, then again after the repeat interval while it still waits.
    // Finance set (finance:remind): on/off, days Queried before Finance officers are reminded, days Audit acknowledged before
    // Finance officers are reminded to acknowledge, days a Draft may sit unsent, repeat interval.
    'fin_remind_enabled' => (bool) env('GWL_FIN_REMIND_ENABLED', true),
    'fin_queried_wait_days' => (int) env('GWL_FIN_QUERIED_WAIT_DAYS', 5),
    'fin_acknowledge_wait_days' => (int) env('GWL_FIN_ACKNOWLEDGE_WAIT_DAYS', 3),
    'fin_draft_wait_days' => (int) env('GWL_FIN_DRAFT_WAIT_DAYS', 7),
    'fin_remind_repeat_days' => (int) env('GWL_FIN_REMIND_REPEAT_DAYS', 3),
    // Audit set (audit:remind): on/off, days With Audit before Audit officers are reminded, repeat interval.
    'audit_remind_enabled' => (bool) env('GWL_AUDIT_REMIND_ENABLED', true),
    'fin_audit_wait_days' => (int) env('GWL_FIN_AUDIT_WAIT_DAYS', 3),
    // Days a sent voucher may stay without an Audit officer before the Audit manager is reminded (nobody in Audit can work on it until then).
    'fin_audit_unassigned_wait_days' => (int) env('GWL_FIN_AUDIT_UNASSIGNED_WAIT_DAYS', 1),
    'audit_remind_repeat_days' => (int) env('GWL_AUDIT_REMIND_REPEAT_DAYS', 3),
    // Seconds the analytics payload of one location is kept (it is also dropped the moment a voucher changes).
    'fin_analytics_cache_seconds' => (int) env('GWL_FIN_ANALYTICS_CACHE_SECONDS', 300),
    // Most rows in one Finance / Audit export (Excel, PDF); the PDF is smaller because it is drawn page by page.
    'fin_export_max_rows' => (int) env('GWL_FIN_EXPORT_MAX_ROWS', 5000),
    'fin_export_pdf_max_rows' => (int) env('GWL_FIN_EXPORT_PDF_MAX_ROWS', 1500),
    // Hours an incident may sit unacknowledged before it is shown as overdue (the alert command chases it).
    'hs_ack_hours' => (int) env('GWL_HS_ACK_HOURS', 24),
    // Days from acknowledgement within which the investigation should be finished.
    'hs_investigation_due_days' => (int) env('GWL_HS_INVESTIGATION_DUE_DAYS', 14),
    // Photos on an incident: the biggest one (MB) and how many one incident may hold.
    'hs_attachment_max_mb' => (int) env('GWL_HS_ATTACHMENT_MAX_MB', 5),
    'hs_attachments_per_incident' => (int) env('GWL_HS_ATTACHMENTS_PER_INCIDENT', 5),
    // Shown in the strip at the top of the report form. Left empty until the team confirms the numbers; the strip is
    // hidden while it is empty. Entries are separated by "|" and written "Label: number" (for example "Ambulance: 193").
    'hs_emergency_contacts' => (string) env('GWL_HS_EMERGENCY_CONTACTS', ''),
    // Phase 2, fire extinguishers and first aid kits. PLACEHOLDERS until the EHS department confirms them.
    // Days before an expiry or service date at which an item shows as "expiring" / "due soon", and the tighter window the
    // overview counts and links to.
    'hs_expiry_warning_days' => (int) env('GWL_HS_EXPIRY_WARNING_DAYS', 60),
    'hs_expiry_critical_days' => (int) env('GWL_HS_EXPIRY_CRITICAL_DAYS', 30),
    // Days between checks: an item not checked for this long shows as "check overdue".
    'hs_check_interval_days' => (int) env('GWL_HS_CHECK_INTERVAL_DAYS', 30),
    // Months added to a service date to pre-fill the next service due (always editable on the form).
    'hs_extinguisher_service_months' => (int) env('GWL_HS_EXTINGUISHER_SERVICE_MONTHS', 12),
    // Years added to a hydrostatic test to pre-fill the next one (always editable). Only a pre-fill: the real interval
    // depends on the extinguisher type and must be confirmed with EHS or the supplier.
    'hs_extinguisher_hydro_years' => (int) env('GWL_HS_EXTINGUISHER_HYDRO_YEARS', 5),
    // Service certificates: the biggest file (MB).
    'hs_equipment_attachment_max_mb' => (int) env('GWL_HS_EQUIPMENT_ATTACHMENT_MAX_MB', 5),
    // When true, whoever sent an incident for approval cannot also approve it (super_admin included).
    'hs_require_second_approver' => (bool) env('GWL_HS_REQUIRE_SECOND_APPROVER', false),
    // Phase 4. Days relative to a due date at which the daily command tells people (negative = overdue): equipment and PPE
    // dates, then action due dates. Comma separated; highest first. Edited on the Settings screen.
    'hs_alert_thresholds' => array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) env('GWL_HS_ALERT_THRESHOLDS', '60,30,7,0,-7,-30'))), fn ($item) => $item !== ''))),
    'hs_action_alert_thresholds' => array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) env('GWL_HS_ACTION_ALERT_THRESHOLDS', '3,0,-7'))), fn ($item) => $item !== ''))),
    // A normal PPE issue or close may be dated up to this many days earlier (never the future). "Already held" rows take any past date.
    'hs_issue_backdate_days' => (int) env('GWL_HS_ISSUE_BACKDATE_DAYS', 7),
    // The most rows in one Excel export; over it the user must narrow the filters.
    'hs_export_max_rows' => (int) env('GWL_HS_EXPORT_MAX_ROWS', 5000),
    // QR labels and site posters (Phase 3b). The printed code holds an ABSOLUTE link, so labels must be printed from
    // PRODUCTION: sheets printed on a developer machine would point at that machine. Blank = use APP_URL.
    'hs_qr_base_url' => env('GWL_HS_QR_BASE_URL') ?: null,
    // The most labels (or posters) in one PDF; keeps the render inside PHP's memory limit.
    'hs_labels_per_pdf_max' => (int) env('GWL_HS_LABELS_PER_PDF_MAX', 120),

    // Rows allowed in one Assets list export (Assets, Phones, Network, Issue Reports). Over the cap the user is asked to
    // narrow the filters; the PDF is far heavier to build, so it has its own, lower cap.
    'assets_list_export_max_rows' => (int) env('GWL_ASSETS_LIST_EXPORT_MAX_ROWS', 5000),
    'assets_list_export_pdf_max_rows' => (int) env('GWL_ASSETS_LIST_EXPORT_PDF_MAX_ROWS', 1500),
    // Asset bulk upload (Assets > Assets > Upload assets): the biggest .xlsx accepted, in MB (keep at or below PHP's
    // upload_max_filesize / post_max_size), and the most data rows one file may hold (the file is read into memory).
    'assets_import_max_mb' => (int) env('GWL_ASSETS_IMPORT_MAX_MB', 5),
    'assets_import_max_rows' => (int) env('GWL_ASSETS_IMPORT_MAX_ROWS', 1000),
    // The same two caps for the Maintenance log export (Excel / PDF).
    'assets_maintenance_export_max_rows' => (int) env('GWL_ASSETS_MAINTENANCE_EXPORT_MAX_ROWS', 5000),
    'assets_maintenance_export_pdf_max_rows' => (int) env('GWL_ASSETS_MAINTENANCE_EXPORT_PDF_MAX_ROWS', 1500),

    // Letters: the most letters one transmittal (batch dispatch) may hold. 50 fits one printed sheet.
    'letters_max_batch_size' => (int) env('GWL_LETTERS_MAX_BATCH_SIZE', 50),

    // Letters: a hand-over still unconfirmed after this many days is "overdue" (amber; red at twice as long) and counts
    // towards the dashboard tile and the Sent tab's Overdue filter.
    'letters_unconfirmed_alert_days' => (int) env('GWL_LETTERS_UNCONFIRMED_ALERT_DAYS', 2),

    // Letters: the sender may remind a recipient about the same hand-over at most once in this many hours.
    'letters_remind_cooldown_hours' => (int) env('GWL_LETTERS_REMIND_COOLDOWN_HOURS', 24),

    // Letters: the most rows one register (preview, Excel or PDF) may have; over it the user is asked to narrow the range.
    'letters_register_max_rows' => (int) env('GWL_LETTERS_REGISTER_MAX_ROWS', 5000),

    // Letters: optional scanned copies of the hardcopy (PDF, JPG, PNG). Off by default: with it off the Scans tab and the
    // file pickers are hidden, LetterScanService refuses and /letters/scans/{scan} is a 404. Scans go on a PRIVATE
    // disk (never 'public'), so back up storage/app/private/letters with the database.
    'letters_scans_enabled' => (bool) env('GWL_LETTERS_SCANS_ENABLED', false),
    'letters_scan_disk' => env('GWL_LETTERS_SCAN_DISK', 'local'),
    // Keep this at or below PHP's upload_max_filesize / post_max_size and Livewire's temporary-upload limit (about 12 MB).
    'letters_scan_max_kb' => (int) env('GWL_LETTERS_SCAN_MAX_KB', 10240),
    'letters_scan_max_files' => (int) env('GWL_LETTERS_SCAN_MAX_FILES', 10),
    // true: a recipient may read a scan while the hardcopy is still in transit (remarks still wait for the confirmation).
    'letters_scan_preview_before_confirm' => (bool) env('GWL_LETTERS_SCAN_PREVIEW_BEFORE_CONFIRM', false),

    // Sign-in page background: 'video' (public/images/bgwater.mp4, the original) or 'gif' (public/images/Running_Water_2.gif).
    'auth_background' => env('GWL_AUTH_BACKGROUND', 'video'),

    // Android Enterprise / MDM (Assets module). Off by default; when off the MDM routes (including the
    // Google webhook) don't register and the MDM sidebar entries are hidden. See docs/assets/mdm.md.
    'mdm_enabled' => (bool) env('GWL_MDM_ENABLED', false),
    'mdm_google_project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
    // Absolute path to the service-account JSON, kept outside the web root and outside git.
    'mdm_credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
    'mdm_enterprise_name' => env('ANDROID_MANAGEMENT_ENTERPRISE_ID'),
    'mdm_enrollment_token_minutes' => (int) env('GWL_MDM_ENROLLMENT_TOKEN_MINUTES', 60),
    'mdm_lost_mode_message' => env('GWL_MDM_LOST_MODE_MESSAGE', 'This phone is company property and has been reported lost. Please contact the number shown to return it.'),
    'mdm_lost_mode_phone' => env('GWL_MDM_LOST_MODE_PHONE'),
    'mdm_lost_mode_address' => env('GWL_MDM_LOST_MODE_ADDRESS'),
    'mdm_pubsub_topic' => env('GWL_MDM_PUBSUB_TOPIC'),
    'mdm_pubsub_subscription' => env('GWL_MDM_PUBSUB_SUBSCRIPTION'),
    // "pull" (local/Laragon, and the fallback if the webhook is down) or "push" (public HTTPS).
    'mdm_pubsub_mode' => env('GWL_MDM_PUBSUB_MODE', 'pull'),
    'mdm_pubsub_push_audience' => env('GWL_MDM_PUBSUB_PUSH_AUDIENCE'),
    'mdm_pubsub_push_service_account' => env('GWL_MDM_PUBSUB_PUSH_SERVICE_ACCOUNT'),
    'mdm_pubsub_push_token' => env('GWL_MDM_PUBSUB_PUSH_TOKEN'),
    'mdm_pubsub_pull_batch' => (int) env('GWL_MDM_PUBSUB_PULL_BATCH', 50),
    'mdm_webhook_rate_per_minute' => (int) env('GWL_MDM_WEBHOOK_RATE_PER_MINUTE', 600),
    'mdm_command_rate_per_minute' => (int) env('GWL_MDM_COMMAND_RATE_PER_MINUTE', 6),
    'mdm_command_valid_minutes' => (int) env('GWL_MDM_COMMAND_VALID_MINUTES', 60),
    'mdm_stale_report_hours' => (int) env('GWL_MDM_STALE_REPORT_HOURS', 24),
    'mdm_event_retention_days' => (int) env('GWL_MDM_EVENT_RETENTION_DAYS', 90),
];
