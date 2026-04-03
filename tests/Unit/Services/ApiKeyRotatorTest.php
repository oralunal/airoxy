<?php

use App\Models\AccessToken;
use App\Services\AccessTokenRotator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('selects the least recently used active token', function () {
    AccessToken::create(['name' => 'Token 1', 'token' => 't1', 'refresh_token' => 'r1', 'usage_order' => 1, 'last_used_at' => now()->subMinutes(10), 'token_expires_at' => now()->addDay()]);
    AccessToken::create(['name' => 'Token 2', 'token' => 't2', 'refresh_token' => 'r2', 'usage_order' => 2, 'last_used_at' => now()->subMinutes(20), 'token_expires_at' => now()->addDay()]);
    AccessToken::create(['name' => 'Token 3', 'token' => 't3', 'refresh_token' => 'r3', 'usage_order' => 3, 'last_used_at' => now()->subMinutes(5), 'token_expires_at' => now()->addDay()]);

    $rotator = new AccessTokenRotator;
    $token = $rotator->selectNext();
    expect($token->name)->toBe('Token 2');
});

it('prefers tokens with null last_used_at', function () {
    AccessToken::create(['name' => 'Used', 'token' => 't1', 'refresh_token' => 'r1', 'usage_order' => 1, 'last_used_at' => now(), 'token_expires_at' => now()->addDay()]);
    AccessToken::create(['name' => 'Never Used', 'token' => 't2', 'refresh_token' => 'r2', 'usage_order' => 2, 'last_used_at' => null, 'token_expires_at' => now()->addDay()]);

    $rotator = new AccessTokenRotator;
    $token = $rotator->selectNext();
    expect($token->name)->toBe('Never Used');
});

it('uses usage_order as tiebreaker', function () {
    AccessToken::create(['name' => 'Order 2', 'token' => 't1', 'refresh_token' => 'r1', 'usage_order' => 2, 'last_used_at' => null, 'token_expires_at' => now()->addDay()]);
    AccessToken::create(['name' => 'Order 1', 'token' => 't2', 'refresh_token' => 'r2', 'usage_order' => 1, 'last_used_at' => null, 'token_expires_at' => now()->addDay()]);

    $rotator = new AccessTokenRotator;
    $token = $rotator->selectNext();
    expect($token->name)->toBe('Order 1');
});

it('skips inactive tokens', function () {
    AccessToken::create(['name' => 'Inactive', 'token' => 't1', 'refresh_token' => 'r1', 'usage_order' => 1, 'is_active' => false, 'token_expires_at' => now()->addDay()]);
    AccessToken::create(['name' => 'Active', 'token' => 't2', 'refresh_token' => 'r2', 'usage_order' => 2, 'is_active' => true, 'token_expires_at' => now()->addDay()]);

    $rotator = new AccessTokenRotator;
    $token = $rotator->selectNext();
    expect($token->name)->toBe('Active');
});

it('skips expired tokens', function () {
    AccessToken::create(['name' => 'Expired', 'token' => 't1', 'refresh_token' => 'r1', 'usage_order' => 1, 'is_active' => true, 'token_expires_at' => now()->subHour()]);
    AccessToken::create(['name' => 'Valid', 'token' => 't2', 'refresh_token' => 'r2', 'usage_order' => 2, 'is_active' => true, 'token_expires_at' => now()->addDay()]);

    $rotator = new AccessTokenRotator;
    $token = $rotator->selectNext();
    expect($token->name)->toBe('Valid');
});

it('skips excluded token IDs', function () {
    AccessToken::create(['name' => 'Token 1', 'token' => 't1', 'refresh_token' => 'r1', 'usage_order' => 1, 'token_expires_at' => now()->addDay()]);
    AccessToken::create(['name' => 'Token 2', 'token' => 't2', 'refresh_token' => 'r2', 'usage_order' => 2, 'token_expires_at' => now()->addDay()]);

    $rotator = new AccessTokenRotator;
    $token = $rotator->selectNext(excludeIds: [1]);
    expect($token->name)->toBe('Token 2');
});

it('returns null when no tokens available', function () {
    $rotator = new AccessTokenRotator;
    $token = $rotator->selectNext();
    expect($token)->toBeNull();
});

it('updates last_used_at after selection', function () {
    AccessToken::create(['name' => 'Token 1', 'token' => 't1', 'refresh_token' => 'r1', 'usage_order' => 1, 'last_used_at' => null, 'token_expires_at' => now()->addDay()]);

    $rotator = new AccessTokenRotator;
    $token = $rotator->selectNext();
    expect($token->last_used_at)->not->toBeNull();
});
