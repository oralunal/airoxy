<?php

use App\Enums\TokenType;
use App\Models\AccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts type to TokenType enum', function () {
    $token = new AccessToken(['type' => 'oauth']);
    expect($token->type)->toBe(TokenType::OAuth);

    $token = new AccessToken(['type' => 'api_key']);
    expect($token->type)->toBe(TokenType::ApiKey);
});

it('detects oauth token type', function () {
    $token = new AccessToken(['type' => 'oauth']);
    expect($token->isOauth())->toBeTrue()
        ->and($token->isApiKey())->toBeFalse();
});

it('detects api key token type', function () {
    $token = new AccessToken(['type' => 'api_key']);
    expect($token->isOauth())->toBeFalse()
        ->and($token->isApiKey())->toBeTrue();
});

it('auto-detects type from token prefix on creation', function () {
    $oauth = AccessToken::create([
        'name' => 'OAuth',
        'token' => 'sk-ant-oat01-abc',
        'refresh_token' => 'sk-ant-ort01-abc',
    ]);
    expect($oauth->type)->toBe(TokenType::OAuth);

    $apiKey = AccessToken::create([
        'name' => 'API Key',
        'token' => 'sk-ant-api03-abc',
    ]);
    expect($apiKey->type)->toBe(TokenType::ApiKey);
});

it('api key tokens with null expiry are valid', function () {
    $token = new AccessToken([
        'type' => 'api_key',
        'is_active' => true,
        'token_expires_at' => null,
    ]);
    expect($token->isValid())->toBeTrue();
});

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
