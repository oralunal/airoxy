<?php

use App\Models\AccessToken;
use App\Models\AnthropicApiKey;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->token = AccessToken::create(['name' => 'Client 1', 'token' => 't1', 'refresh_token' => 'r1']);
    $this->apiKey = AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'k1', 'usage_order' => 1]);
});

it('shows recent request logs', function () {
    RequestLog::create([
        'access_token_id' => $this->token->id,
        'api_key_id' => $this->apiKey->id,
        'model' => 'claude-sonnet-4-6',
        'is_stream' => false,
        'input_tokens' => 100,
        'output_tokens' => 50,
        'estimated_cost_usd' => 0.001,
        'status_code' => 200,
        'duration_ms' => 500,
        'requested_at' => now(),
        'created_at' => now(),
    ]);

    $this->artisan('airoxy:logs')
        ->assertExitCode(0)
        ->expectsOutputToContain('claude-sonnet-4-6');
});

it('shows stats summary', function () {
    RequestLog::create([
        'access_token_id' => $this->token->id,
        'api_key_id' => $this->apiKey->id,
        'model' => 'claude-sonnet-4-6',
        'is_stream' => false,
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'estimated_cost_usd' => 0.01,
        'status_code' => 200,
        'duration_ms' => 500,
        'requested_at' => now(),
        'created_at' => now(),
    ]);

    $this->artisan('airoxy:stats')
        ->assertExitCode(0)
        ->expectsOutputToContain('1,000')
        ->expectsOutputToContain('500');
});
