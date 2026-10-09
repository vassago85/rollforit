<?php

return [

    'api_key' => env('SHOPIFY_API_KEY', ''),

    'api_secret' => env('SHOPIFY_API_SECRET', ''),

    'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),

    'scopes' => env('SHOPIFY_SCOPES', 'write_discounts,read_products,write_online_store_pages,write_app_proxy,read_orders'),

    'http_timeout' => (int) env('SHOPIFY_HTTP_TIMEOUT', 20),

    // Refresh the offline access token this many seconds before it expires.
    'token_refresh_skew' => 300,

    'proxy' => [
        // Signed proxy requests older than this are rejected.
        'max_age_seconds' => (int) env('ROLL_PROXY_MAX_AGE', 300),
    ],

    'roll' => [
        'email_pepper' => env('ROLL_EMAIL_PEPPER', ''),
        'ip_rolls_per_day' => (int) env('ROLL_IP_PER_DAY', 5),
        'ip_rolls_per_10_minutes' => (int) env('ROLL_IP_PER_10_MIN', 3),
        'max_cart_lines' => 100,
        'max_line_quantity' => 999,
    ],

    'retention' => [
        'roll_years' => (int) env('ROLL_RETENTION_YEARS', 3),
        'ip_days' => (int) env('ROLL_IP_RETENTION_DAYS', 90),
    ],

    'test_mode' => [
        'preview_minutes' => 120,
        'code_minutes' => 60,
    ],

    'terms_handle' => 'roll-for-it-terms',

];
