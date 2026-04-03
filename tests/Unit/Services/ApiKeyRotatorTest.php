<?php

use App\Models\AnthropicApiKey;
use App\Services\ApiKeyRotator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('selects the least recently used active key', function () {
    AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'key-1', 'usage_order' => 1, 'last_used_at' => now()->subMinutes(10)]);
    AnthropicApiKey::create(['name' => 'Key 2', 'api_key' => 'key-2', 'usage_order' => 2, 'last_used_at' => now()->subMinutes(20)]);
    AnthropicApiKey::create(['name' => 'Key 3', 'api_key' => 'key-3', 'usage_order' => 3, 'last_used_at' => now()->subMinutes(5)]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();
    expect($key->name)->toBe('Key 2');
});

it('prefers keys with null last_used_at', function () {
    AnthropicApiKey::create(['name' => 'Used', 'api_key' => 'key-1', 'usage_order' => 1, 'last_used_at' => now()]);
    AnthropicApiKey::create(['name' => 'Never Used', 'api_key' => 'key-2', 'usage_order' => 2, 'last_used_at' => null]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();
    expect($key->name)->toBe('Never Used');
});

it('uses usage_order as tiebreaker', function () {
    AnthropicApiKey::create(['name' => 'Order 2', 'api_key' => 'key-1', 'usage_order' => 2, 'last_used_at' => null]);
    AnthropicApiKey::create(['name' => 'Order 1', 'api_key' => 'key-2', 'usage_order' => 1, 'last_used_at' => null]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();
    expect($key->name)->toBe('Order 1');
});

it('skips inactive keys', function () {
    AnthropicApiKey::create(['name' => 'Inactive', 'api_key' => 'key-1', 'usage_order' => 1, 'is_active' => false]);
    AnthropicApiKey::create(['name' => 'Active', 'api_key' => 'key-2', 'usage_order' => 2, 'is_active' => true]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();
    expect($key->name)->toBe('Active');
});

it('skips excluded key IDs', function () {
    AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'key-1', 'usage_order' => 1]);
    AnthropicApiKey::create(['name' => 'Key 2', 'api_key' => 'key-2', 'usage_order' => 2]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext(excludeIds: [1]);
    expect($key->name)->toBe('Key 2');
});

it('returns null when no keys available', function () {
    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();
    expect($key)->toBeNull();
});

it('updates last_used_at after selection', function () {
    AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'key-1', 'usage_order' => 1, 'last_used_at' => null]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();
    expect($key->last_used_at)->not->toBeNull();
});
