<?php

return [
    'auto_checkout_time' => env('GWL_AUTO_CHECKOUT_TIME', env('GWCL_VISITORS_AUTO_CHECKOUT_TIME', '18:00')),
    'carry_over_expiry_days' => (int) env('GWL_CARRY_OVER_EXPIRY_DAYS', 90),
    'leave_notification_poll_seconds' => (int) env('GWL_LEAVE_NOTIFICATION_POLL_SECONDS', 90),
    'visitor_kiosk_reset_seconds' => (int) env('GWL_VISITOR_KIOSK_RESET_SECONDS', 5),
    'max_import_failure_percent' => (int) env('GWL_MAX_IMPORT_FAILURE_PERCENT', 20),
];
