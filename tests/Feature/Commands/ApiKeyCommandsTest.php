<?php

use App\Models\AnthropicApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds a new API key', function () {
    $this->artisan('airoxy:api-key:add', [
        'api_key' => 'sk-ant-api03-test-key',
        '--name' => 'Test Key',
    ])->assertExitCode(0);

    expect(AnthropicApiKey::count())->toBe(1);
    expect(AnthropicApiKey::first()->name)->toBe('Test Key');
    expect(AnthropicApiKey::first()->api_key)->toBe('sk-ant-api03-test-key');
});

it('lists API keys with masked values', function () {
    AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'sk-ant-api03-abcdefghijklmnop', 'usage_order' => 1]);

    $this->artisan('airoxy:api-key:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('Key 1');
});

it('removes an API key by ID', function () {
    $key = AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'test', 'usage_order' => 1]);

    $this->artisan('airoxy:api-key:remove', ['id' => $key->id])
        ->assertExitCode(0);

    expect(AnthropicApiKey::count())->toBe(0);
});

it('toggles an API key active status', function () {
    $key = AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'test', 'usage_order' => 1, 'is_active' => true]);

    $this->artisan('airoxy:api-key:toggle', ['id' => $key->id])
        ->assertExitCode(0);

    $key->refresh();
    expect($key->is_active)->toBeFalse();
});
