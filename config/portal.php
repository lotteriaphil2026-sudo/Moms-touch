<?php
return [
    'financial_operations_enabled' => (bool) env('PORTAL_FINANCIAL_OPERATIONS_ENABLED', false),
    'registration_enabled' => (bool) env('PORTAL_REGISTRATION_ENABLED', false),
    'deposit_qr_demo_mode' => (bool) env('PORTAL_DEPOSIT_QR_DEMO_MODE', false),
    // Basis points are fractions of one percent: 60 bps = 0.60% per day.
    'referral_incentive_basis_points' => 500,
    'plans' => [
        'select' => ['name' => 'Mom’s Select', 'minimum_cents' => 500000, 'daily_rate_bps' => 60, 'term_days' => 160],
        'prime' => ['name' => 'Mom’s Prime', 'minimum_cents' => 5000000, 'daily_rate_bps' => 70, 'term_days' => 130],
        'signature' => ['name' => 'Mom’s Signature', 'minimum_cents' => 25000000, 'daily_rate_bps' => 90, 'term_days' => 100],
    ],
];
