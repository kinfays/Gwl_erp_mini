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
    'visitor_kiosk_reset_seconds' => (int) env('GWL_VISITOR_KIOSK_RESET_SECONDS', 5),
    'max_import_failure_percent' => (int) env('GWL_MAX_IMPORT_FAILURE_PERCENT', 20),
    'credit_union_module_enabled' => (bool) env('GWL_CREDIT_UNION_MODULE_ENABLED', false),
    'credit_union_membership_form_fee' => (float) env('GWL_CREDIT_UNION_MEMBERSHIP_FORM_FEE', 20),
    'credit_union_initial_share_amount' => (float) env('GWL_CREDIT_UNION_INITIAL_SHARE_AMOUNT', 200),
    'credit_union_loan_annual_interest_rate_percent' => (float) env('GWL_CREDIT_UNION_LOAN_INTEREST_RATE', 15.0),
    'credit_union_loan_multiple_without_guarantor' => (float) env('GWL_CREDIT_UNION_LOAN_MULTIPLE_WITHOUT_GUARANTOR', 2),

    // Letters: the most letters one transmittal (batch dispatch) may hold. 50 fits one printed sheet.
    'letters_max_batch_size' => (int) env('GWL_LETTERS_MAX_BATCH_SIZE', 50),

    // Letters: a hand-over still unconfirmed after this many days is "overdue" (amber; red at twice as long) and counts
    // towards the dashboard tile and the Sent tab's Overdue filter.
    'letters_unconfirmed_alert_days' => (int) env('GWL_LETTERS_UNCONFIRMED_ALERT_DAYS', 2),

    // Letters: the sender may remind a recipient about the same hand-over at most once in this many hours.
    'letters_remind_cooldown_hours' => (int) env('GWL_LETTERS_REMIND_COOLDOWN_HOURS', 24),

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
