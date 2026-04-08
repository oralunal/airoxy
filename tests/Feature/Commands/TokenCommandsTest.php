<?php

use App\Enums\TokenType;
use App\Models\AccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('adds a new access token', function () {
    $this->artisan('airoxy:token:add', [
        'token' => 'sk-ant-oat01-test',
        'refresh_token' => 'sk-ant-ort01-test',
        '--name' => 'Test Token',
        '--expires-in' => '3600',
    ])->assertExitCode(0);

    expect(AccessToken::count())->toBe(1);

    $token = AccessToken::first();
    expect($token->name)->toBe('Test Token')
        ->and($token->token)->toBe('sk-ant-oat01-test')
        ->and($token->refresh_token)->toBe('sk-ant-ort01-test');
});

it('lists tokens with masked values', function () {
    AccessToken::create([
        'name' => 'Client 1',
        'token' => 'sk-ant-oat01-abcdefghijklmnop',
        'refresh_token' => 'sk-ant-ort01-test',
        'token_expires_at' => now()->addDay(),
    ]);

    $this->artisan('airoxy:token:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('Client 1');
});

it('removes a token by ID', function () {
    $token = AccessToken::create([
        'name' => 'Test',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
    ]);

    $this->artisan('airoxy:token:remove', ['id' => $token->id])
        ->assertExitCode(0);

    expect(AccessToken::count())->toBe(0);
});

it('adds an API key token without refresh_token', function () {
    $this->artisan('airoxy:token:add', [
        'token' => 'sk-ant-api03-test-key',
        '--name' => 'API Key',
    ])->assertExitCode(0);

    $token = AccessToken::first();
    expect($token->type)->toBe(TokenType::ApiKey)
        ->and($token->refresh_token)->toBeNull()
        ->and($token->token_expires_at)->toBeNull();
});

it('auto-detects OAuth type from token prefix', function () {
    $this->artisan('airoxy:token:add', [
        'token' => 'sk-ant-oat01-test',
        'refresh_token' => 'sk-ant-ort01-test',
        '--name' => 'OAuth Token',
    ])->assertExitCode(0);

    $token = AccessToken::first();
    expect($token->type)->toBe(TokenType::OAuth);
});

it('errors when OAuth token is added without refresh_token', function () {
    $this->artisan('airoxy:token:add', [
        'token' => 'sk-ant-oat01-test',
        '--name' => 'Missing Refresh',
    ])->assertExitCode(1);

    expect(AccessToken::count())->toBe(0);
});

it('rejects tokens with unrecognized prefix', function () {
    $this->artisan('airoxy:token:add', [
        'token' => 'invalid-token-format',
        '--name' => 'Bad Token',
    ])->assertExitCode(1);

    expect(AccessToken::count())->toBe(0);
});

it('refreshes a specific token', function () {
    Http::fake([
        'platform.claude.com/*' => Http::response([
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 28800,
        ]),
    ]);

    $token = AccessToken::create([
        'name' => 'Test',
        'token' => 'old-token',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => now()->subHour(),
    ]);

    $this->artisan('airoxy:token:refresh', ['--id' => $token->id])
        ->assertExitCode(0);

    $token->refresh();
    expect($token->token)->toBe('new-token');
});

it('refreshes all active tokens', function () {
    Http::fakeSequence()
        ->push(['access_token' => 'new-token-1', 'refresh_token' => 'new-refresh-1', 'expires_in' => 28800])
        ->push(['access_token' => 'new-token-2', 'refresh_token' => 'new-refresh-2', 'expires_in' => 28800]);

    AccessToken::create(['name' => 'T1', 'token' => 'old-1', 'refresh_token' => 'r1']);
    AccessToken::create(['name' => 'T2', 'token' => 'old-2', 'refresh_token' => 'r2']);

    $this->artisan('airoxy:token:refresh')
        ->assertExitCode(0);
});
