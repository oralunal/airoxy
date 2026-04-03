<?php

use App\Models\AnthropicApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('encrypts api_key when storing', function () {
    $key = AnthropicApiKey::create([
        'name' => 'Test Key',
        'api_key' => 'sk-ant-api03-test-key-value',
        'usage_order' => 1,
    ]);

    $raw = DB::table('anthropic_api_keys')->where('id', $key->id)->value('api_key');
    expect($raw)->not->toBe('sk-ant-api03-test-key-value');

    $key->refresh();
    expect($key->api_key)->toBe('sk-ant-api03-test-key-value');
});

it('masks the api key for display showing last 8 chars', function () {
    $key = new AnthropicApiKey(['api_key' => 'sk-ant-api03-abcdefghijklmnop']);
    $masked = $key->masked_key;

    expect($masked)
        ->toEndWith('ijklmnop')
        ->toContain('***');
});
