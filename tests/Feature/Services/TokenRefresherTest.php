<?php

use App\Models\AccessToken;
use App\Services\TokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('refreshes a token successfully', function () {
    Http::fake([
        'platform.claude.com/*' => Http::response([
            'token_type' => 'Bearer',
            'access_token' => 'sk-ant-oat01-new-token',
            'expires_in' => 28800,
            'refresh_token' => 'sk-ant-ort01-new-refresh',
            'scope' => 'user:profile user:inference',
        ]),
    ]);

    $token = AccessToken::create([
        'name' => 'Test',
        'token' => 'sk-ant-oat01-old-token',
        'refresh_token' => 'sk-ant-ort01-old-refresh',
        'token_expires_at' => now()->subHour(),
        'refresh_fail_count' => 1,
    ]);

    $refresher = new TokenRefresher;
    $result = $refresher->refresh($token);

    expect($result)->toBeTrue();

    $token->refresh();
    expect($token->token)->toBe('sk-ant-oat01-new-token')
        ->and($token->refresh_token)->toBe('sk-ant-ort01-new-refresh')
        ->and($token->refresh_fail_count)->toBe(0)
        ->and($token->token_expires_at)->toBeGreaterThan(now());
});

it('increments fail count on refresh failure', function () {
    Http::fake([
        'platform.claude.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $token = AccessToken::create([
        'name' => 'Test',
        'token' => 'old-token',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => now()->subHour(),
        'refresh_fail_count' => 0,
    ]);

    $refresher = new TokenRefresher;
    $result = $refresher->refresh($token);

    expect($result)->toBeFalse();
    $token->refresh();
    expect($token->refresh_fail_count)->toBe(1)
        ->and($token->is_active)->toBeTrue();
});

it('skips API key tokens in refreshAll', function () {
    AccessToken::create([
        'name' => 'API Key',
        'token' => 'sk-ant-api03-test',
        'is_active' => true,
    ]);

    $refresher = new TokenRefresher;
    $result = $refresher->refreshAll();

    expect($result['refreshed'])->toBe(0)
        ->and($result['failed'])->toBe(0);
});

it('deactivates token after 3 consecutive failures', function () {
    Http::fake([
        'platform.claude.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $token = AccessToken::create([
        'name' => 'Test',
        'token' => 'old-token',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => now()->subHour(),
        'refresh_fail_count' => 2,
    ]);

    $refresher = new TokenRefresher;
    $refresher->refresh($token);

    $token->refresh();
    expect($token->refresh_fail_count)->toBe(3)
        ->and($token->is_active)->toBeFalse();
});
