<?php

use App\Http\Middleware\AuthenticateToken;
use App\Models\AccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::post('/test-auth', fn () => response()->json(['ok' => true]))
        ->middleware(AuthenticateToken::class);
});

it('returns 401 when x-api-key header is missing', function () {
    $response = $this->postJson('/test-auth');

    $response->assertStatus(401)
        ->assertJson([
            'type' => 'error',
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Invalid API key',
            ],
        ]);
});

it('returns 401 for invalid token', function () {
    $response = $this->postJson('/test-auth', [], ['x-api-key' => 'invalid-token']);
    $response->assertStatus(401);
});

it('returns 401 for inactive token', function () {
    AccessToken::create([
        'token' => 'inactive-token',
        'refresh_token' => 'refresh',
        'is_active' => false,
    ]);

    $response = $this->postJson('/test-auth', [], ['x-api-key' => 'inactive-token']);
    $response->assertStatus(401);
});

it('returns 401 for expired token', function () {
    AccessToken::create([
        'token' => 'expired-token',
        'refresh_token' => 'refresh',
        'is_active' => true,
        'token_expires_at' => now()->subHour(),
    ]);

    $response = $this->postJson('/test-auth', [], ['x-api-key' => 'expired-token']);
    $response->assertStatus(401);
});

it('allows valid token through', function () {
    AccessToken::create([
        'token' => 'valid-token',
        'refresh_token' => 'refresh',
        'is_active' => true,
        'token_expires_at' => now()->addDay(),
    ]);

    $response = $this->postJson('/test-auth', [], ['x-api-key' => 'valid-token']);
    $response->assertStatus(200);
});

it('updates last_used_at on valid token', function () {
    $token = AccessToken::create([
        'token' => 'valid-token',
        'refresh_token' => 'refresh',
        'is_active' => true,
        'token_expires_at' => now()->addDay(),
        'last_used_at' => null,
    ]);

    $this->postJson('/test-auth', [], ['x-api-key' => 'valid-token']);

    $token->refresh();
    expect($token->last_used_at)->not->toBeNull();
});
