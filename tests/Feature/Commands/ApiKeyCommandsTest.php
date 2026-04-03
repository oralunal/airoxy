<?php

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds a new API key with auto-generated key', function () {
    $this->artisan('airoxy:api-key:add', [
        '--name' => 'Test Key',
    ])->assertExitCode(0);

    expect(ApiKey::count())->toBe(1);
    expect(ApiKey::first()->name)->toBe('Test Key');
    expect(ApiKey::first()->key)->toStartWith('ak-');
});

it('lists API keys with masked values', function () {
    ApiKey::create(['name' => 'Key 1', 'key' => ApiKey::generateKey()]);

    $this->artisan('airoxy:api-key:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('Key 1');
});

it('removes an API key by ID', function () {
    $key = ApiKey::create(['name' => 'Key 1', 'key' => ApiKey::generateKey()]);

    $this->artisan('airoxy:api-key:remove', ['id' => $key->id])
        ->assertExitCode(0);

    expect(ApiKey::count())->toBe(0);
});

it('toggles an API key active status', function () {
    $key = ApiKey::create(['name' => 'Key 1', 'key' => ApiKey::generateKey(), 'is_active' => true]);

    $this->artisan('airoxy:api-key:toggle', ['id' => $key->id])
        ->assertExitCode(0);

    $key->refresh();
    expect($key->is_active)->toBeFalse();
});
