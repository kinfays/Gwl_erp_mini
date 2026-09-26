<?php

return [
    'auto_checkout_time' => env('GWL_AUTO_CHECKOUT_TIME', env('GWCL_VISITORS_AUTO_CHECKOUT_TIME', '18:00')),
    'carry_over_expiry_days' => (int) env('GWL_CARRY_OVER_EXPIRY_DAYS', 90),
    'leave_notification_poll_seconds' => (int) env('GWL_LEAVE_NOTIFICATION_POLL_SECONDS', 90),
    'visitor_kiosk_reset_seconds' => (int) env('GWL_VISITOR_KIOSK_RESET_SECONDS', 5),
    'max_import_failure_percent' => (int) env('GWL_MAX_IMPORT_FAILURE_PERCENT', 20),
    'credit_union_module_enabled' => (bool) env('GWL_CREDIT_UNION_MODULE_ENABLED', false),
    'credit_union_membership_form_fee' => (float) env('GWL_CREDIT_UNION_MEMBERSHIP_FORM_FEE', 20),
    'credit_union_initial_share_amount' => (float) env('GWL_CREDIT_UNION_INITIAL_SHARE_AMOUNT', 200),
    'credit_union_loan_annual_interest_rate_percent' => (float) env('GWL_CREDIT_UNION_LOAN_INTEREST_RATE', 15.0),
    'credit_union_loan_multiple_without_guarantor' => (float) env('GWL_CREDIT_UNION_LOAN_MULTIPLE_WITHOUT_GUARANTOR', 2),
];
