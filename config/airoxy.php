<?php

return [

    'host' => env('AIROXY_HOST', '0.0.0.0'),
    'port' => env('AIROXY_PORT', 3800),

    'anthropic_api_url' => 'https://api.anthropic.com/v1/messages?beta=true',
    'anthropic_version' => '2023-06-01',

    'token_refresh_interval' => env('AIROXY_TOKEN_REFRESH_INTERVAL', 360),
    'log_retention_days' => env('AIROXY_LOG_RETENTION_DAYS', 3),

    'oauth' => [
        'endpoint' => 'https://platform.claude.com/v1/oauth/token',
        'client_id' => '9d1c250a-e61b-44d9-88ed-5944d1962f5e',
        'scope' => 'user:profile user:inference',
    ],

    'pricing' => [
        'claude-opus-4-6' => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-6' => ['input' => 3.00,  'output' => 15.00],
        'claude-haiku-4-5' => ['input' => 0.80,  'output' => 4.00],
        'claude-haiku-4-5-20251001' => ['input' => 0.80,  'output' => 4.00],
        'claude-opus-4-5' => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-5-20251101' => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-5' => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4-5-20250929' => ['input' => 3.00,  'output' => 15.00],
        'claude-opus-4-1' => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-1-20250805' => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-0' => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-20250514' => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-0' => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4-20250514' => ['input' => 3.00,  'output' => 15.00],
        'claude-3-haiku-20240307' => ['input' => 0.25,  'output' => 1.25],

        'cache_write_multiplier' => 1.25,
        'cache_read_multiplier' => 0.10,

        'default' => ['input' => 3.00, 'output' => 15.00],
    ],
];
