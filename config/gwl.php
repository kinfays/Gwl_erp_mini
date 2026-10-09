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
        // Junior Gd. Levels 4-6.
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
    'credit_union_module_enabled' => (bool) env('GWL_CREDIT_UNION_MODULE_ENABLED', false),
    'credit_union_membership_form_fee' => (float) env('GWL_CREDIT_UNION_MEMBERSHIP_FORM_FEE', 20),
    'credit_union_initial_share_amount' => (float) env('GWL_CREDIT_UNION_INITIAL_SHARE_AMOUNT', 200),
    'credit_union_loan_annual_interest_rate_percent' => (float) env('GWL_CREDIT_UNION_LOAN_INTEREST_RATE', 15.0),
    'credit_union_loan_multiple_without_guarantor' => (float) env('GWL_CREDIT_UNION_LOAN_MULTIPLE_WITHOUT_GUARANTOR', 2),

    // Health & Safety (incident reporting now; equipment and PPE later): off by default; when off the routes don't register
    // and the module is hidden from navigation (docs/health-safety-module-design.md).
    'health_safety_module_enabled' => (bool) env('GWL_HEALTH_SAFETY_MODULE_ENABLED', false),

    // Regional Blog (PR articles about activities, meetings, workshops and training, read by the staff of one region): on by
    // default; when off the routes don't register and the module is hidden from navigation (docs/14-module-regional-blog.md).
    'blog_module_enabled' => (bool) env('GWL_BLOG_MODULE_ENABLED', true),
    // Biggest cover photo accepted (MB). Keep at or below PHP upload_max_filesize / post_max_size.
    'blog_cover_max_mb' => (int) env('GWL_BLOG_COVER_MAX_MB', 3),
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

    // Commercial (billing & meter-reading analytics): off by default; when off the routes don't register and the module
    // is hidden from navigation. Reports arrive as Excel uploads (docs/commercial-module-design.md).
    'commercial_module_enabled' => (bool) env('GWL_COMMERCIAL_MODULE_ENABLED', false),
    // The biggest report upload accepted, in MB. Keep it at or below PHP's upload_max_filesize / post_max_size and
    // Livewire's temporary-upload limit (about 12 MB).
    'commercial_import_max_mb' => (int) env('GWL_COMMERCIAL_IMPORT_MAX_MB', 10),
    // Reader analytics thresholds (used from Phase 2). A reader needs this many visits in a month before a skip-rate
    // outlier is flagged, so a reader with 197 visits is not judged on noise.
    'commercial_min_visits_for_outlier' => (int) env('GWL_COMMERCIAL_MIN_VISITS_FOR_OUTLIER', 200),
    'commercial_outlier_zscore' => (float) env('GWL_COMMERCIAL_OUTLIER_ZSCORE', 2.0),
    // A reader is under-utilised below / over-loaded above this percentage of the median monthly visits.
    // Privacy: a user who may NOT see individual readers sees no reading figure grouped by home district when fewer than
    // this many distinct readers had visits in the months shown (in a district of one, the row IS that person).
    'commercial_min_readers_for_district_figures' => (int) env('GWL_COMMERCIAL_MIN_READERS_FOR_DISTRICT_FIGURES', 3),
    'commercial_workload_low_pct' => (int) env('GWL_COMMERCIAL_WORKLOAD_LOW_PCT', 50),
    'commercial_workload_high_pct' => (int) env('GWL_COMMERCIAL_WORKLOAD_HIGH_PCT', 150),
    // PLACEHOLDER targets until the Commercial team confirms the real ones (design section 10, question 5).
    'commercial_target_skip_rate_pct' => (float) env('GWL_COMMERCIAL_TARGET_SKIP_RATE_PCT', 10),
    'commercial_target_coverage_pct' => (float) env('GWL_COMMERCIAL_TARGET_COVERAGE_PCT', 90),
    'commercial_target_collection_pct' => (float) env('GWL_COMMERCIAL_TARGET_COLLECTION_PCT', 95),
    // Commercial exports (Excel / PDF): the most rows one file may hold (all its tables together). Over it the user is
    // asked to narrow the filters instead of being handed a huge file.
    'commercial_export_max_rows' => (int) env('GWL_COMMERCIAL_EXPORT_MAX_ROWS', 5000),
    // PDFs are far heavier to build than spreadsheets (Dompdf is slow and memory-hungry), so they have their own, lower cap.
    'commercial_export_pdf_max_rows' => (int) env('GWL_COMMERCIAL_EXPORT_PDF_MAX_ROWS', 1500),
    // Billing route exceptions (B11), all PLACEHOLDERS to confirm. A route needs at least this many customers behind a
    // percentage before it can be flagged on it (so one-customer routes do not flood the list); then the unbilled rate
    // and the estimated-bill share are flagged above these percentages, and a closing balance at or below minus the
    // credit amount (GH¢) is a heavy credit.
    'commercial_exception_min_customers' => (int) env('GWL_COMMERCIAL_EXCEPTION_MIN_CUSTOMERS', 3),
    'commercial_exception_high_unbilled_pct' => (float) env('GWL_COMMERCIAL_EXCEPTION_HIGH_UNBILLED_PCT', 25),
    'commercial_exception_high_estimation_pct' => (float) env('GWL_COMMERCIAL_EXCEPTION_HIGH_ESTIMATION_PCT', 75),
    'commercial_exception_credit_amount' => (float) env('GWL_COMMERCIAL_EXCEPTION_CREDIT_AMOUNT', 300),
    // Upload reminders: when no upload of a report type has arrived for a region for this many days, the officers who
    // upload for that region are told (daily, at most once every repeat_days). The days are PLACEHOLDERS: how often each
    // report really arrives is still to be confirmed (design 10, question 9). The overdue badge on the Summary page shows
    // whether or not the notifications are on.
    'commercial_reminders_enabled' => (bool) env('GWL_COMMERCIAL_REMINDERS_ENABLED', true),
    'commercial_reminder_reading_days' => (int) env('GWL_COMMERCIAL_REMINDER_READING_DAYS', 10),
    'commercial_reminder_billing_days' => (int) env('GWL_COMMERCIAL_REMINDER_BILLING_DAYS', 35),
    'commercial_reminder_repeat_days' => (int) env('GWL_COMMERCIAL_REMINDER_REPEAT_DAYS', 7),
    // Reader scorecard (R13): weights of the three measures. A first guess, shown on screen and labelled "indicative";
    // they are re-normalised for a reader whose consistency cannot be worked out yet.
    'commercial_scorecard_weights' => [
        'volume' => (float) env('GWL_COMMERCIAL_SCORE_WEIGHT_VOLUME', 0.4),
        'skip' => (float) env('GWL_COMMERCIAL_SCORE_WEIGHT_SKIP', 0.4),
        'consistency' => (float) env('GWL_COMMERCIAL_SCORE_WEIGHT_CONSISTENCY', 0.2),
    ],

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
