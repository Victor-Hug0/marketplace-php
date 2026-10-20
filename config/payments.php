<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mock Payment Gateway
    |--------------------------------------------------------------------------
    |
    | Simulated gateway used while there is no real payment provider. The
    | webhook endpoint rejects every request while the secret is empty.
    |
    */

    'mock' => [
        'webhook_secret' => env('PAYMENTS_MOCK_WEBHOOK_SECRET'),
        'webhook_header' => 'X-Mock-Webhook-Secret',
        'pix_ttl_minutes' => (int) env('PAYMENTS_MOCK_PIX_TTL_MINUTES', 30),
        'boleto_due_days' => (int) env('PAYMENTS_MOCK_BOLETO_DUE_DAYS', 3),
        'checkout_base_url' => env('PAYMENTS_MOCK_CHECKOUT_URL', 'https://mock-payments.local/checkout'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform Commission
    |--------------------------------------------------------------------------
    |
    | Fallback used when no platform_fee_rules row is in effect, and seed
    | value for the global MVP rule. Basis points: 1000 = 10%.
    |
    */

    'commission' => [
        'default_percent_bps' => (int) env('PLATFORM_COMMISSION_BPS', 1000),
    ],

];
