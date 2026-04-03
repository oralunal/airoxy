<?php

use App\Models\AccessToken;
use App\Models\ApiKey;
use App\Models\DailyStat;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('aggregates request logs into daily stats', function () {
    $token = AccessToken::create(['name' => 'T1', 'token' => 't1', 'refresh_token' => 'r1']);
    $key = ApiKey::create(['name' => 'K1', 'key' => 'ak-test-key']);

    $yesterday = now()->subDay()->startOfDay();

    RequestLog::create([
        'access_token_id' => $token->id,
        'api_key_id' => $key->id,
        'model' => 'claude-sonnet-4-6',
        'is_stream' => false,
        'input_tokens' => 100,
        'output_tokens' => 50,
        'cache_creation_input_tokens' => 20,
        'cache_read_input_tokens' => 30,
        'estimated_cost_usd' => 0.001,
        'status_code' => 200,
        'duration_ms' => 500,
        'requested_at' => $yesterday,
        'created_at' => $yesterday,
    ]);

    $this->artisan('airoxy:aggregate-stats')->assertExitCode(0);

    expect(DailyStat::where('access_token_id', $token->id)->where('model', 'claude-sonnet-4-6')->count())->toBe(1);

    $stat = DailyStat::where('access_token_id', $token->id)->where('model', 'claude-sonnet-4-6')->first();
    expect($stat->total_requests)->toBe(1)
        ->and($stat->total_input_tokens)->toBe(100)
        ->and($stat->total_output_tokens)->toBe(50);
});

it('purges old request logs', function () {
    $token = AccessToken::create(['name' => 'T1', 'token' => 't1', 'refresh_token' => 'r1']);
    $key = ApiKey::create(['name' => 'K1', 'key' => 'ak-test-key-2']);

    // Old log (4 days ago)
    RequestLog::create([
        'access_token_id' => $token->id, 'api_key_id' => $key->id,
        'model' => 'claude-sonnet-4-6', 'is_stream' => false,
        'status_code' => 200, 'duration_ms' => 100,
        'requested_at' => now()->subDays(4), 'created_at' => now()->subDays(4),
    ]);

    // Recent log (1 day ago)
    RequestLog::create([
        'access_token_id' => $token->id, 'api_key_id' => $key->id,
        'model' => 'claude-sonnet-4-6', 'is_stream' => false,
        'status_code' => 200, 'duration_ms' => 100,
        'requested_at' => now()->subDay(), 'created_at' => now()->subDay(),
    ]);

    $this->artisan('airoxy:purge-logs')->assertExitCode(0);

    expect(RequestLog::count())->toBe(1);
});
