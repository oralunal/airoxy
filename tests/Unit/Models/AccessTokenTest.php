<?php

use App\Models\AccessToken;

it('detects expired tokens', function () {
    $token = new AccessToken(['token_expires_at' => now()->subHour()]);
    expect($token->isExpired())->toBeTrue();

    $token = new AccessToken(['token_expires_at' => now()->addHour()]);
    expect($token->isExpired())->toBeFalse();
});

it('validates token is active and not expired', function () {
    $valid = new AccessToken([
        'is_active' => true,
        'token_expires_at' => now()->addHour(),
    ]);
    expect($valid->isValid())->toBeTrue();

    $inactive = new AccessToken([
        'is_active' => false,
        'token_expires_at' => now()->addHour(),
    ]);
    expect($inactive->isValid())->toBeFalse();

    $expired = new AccessToken([
        'is_active' => true,
        'token_expires_at' => now()->subHour(),
    ]);
    expect($expired->isValid())->toBeFalse();
});

it('masks the token for display', function () {
    $token = new AccessToken(['token' => 'sk-ant-oat01-abcdefghijklmnop']);
    $masked = $token->masked_token;

    expect($masked)
        ->toStartWith('sk-ant-o')
        ->toEndWith('mnop')
        ->toContain('***');
});
