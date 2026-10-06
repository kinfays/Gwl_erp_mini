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
    'commercial_workload_low_pct' => (int) env('GWL_COMMERCIAL_WORKLOAD_LOW_PCT', 50),
    'commercial_workload_high_pct' => (int) env('GWL_COMMERCIAL_WORKLOAD_HIGH_PCT', 150),
    // PLACEHOLDER targets until the Commercial team confirms the real ones (design section 10, question 5).
    'commercial_target_skip_rate_pct' => (float) env('GWL_COMMERCIAL_TARGET_SKIP_RATE_PCT', 10),
    'commercial_target_coverage_pct' => (float) env('GWL_COMMERCIAL_TARGET_COVERAGE_PCT', 90),
    'commercial_target_collection_pct' => (float) env('GWL_COMMERCIAL_TARGET_COLLECTION_PCT', 95),

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
