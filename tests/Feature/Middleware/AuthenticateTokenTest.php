<?php

use App\Http\Middleware\AuthenticateToken;
use App\Models\ApiKey;
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

it('returns 401 for invalid key', function () {
    $response = $this->postJson('/test-auth', [], ['x-api-key' => 'invalid-key']);
    $response->assertStatus(401);
});

it('returns 401 for inactive key', function () {
    ApiKey::create([
        'key' => 'inactive-key',
        'is_active' => false,
    ]);

    $response = $this->postJson('/test-auth', [], ['x-api-key' => 'inactive-key']);
    $response->assertStatus(401);
});

it('allows valid key through', function () {
    ApiKey::create([
        'key' => 'valid-key',
        'is_active' => true,
    ]);

    $response = $this->postJson('/test-auth', [], ['x-api-key' => 'valid-key']);
    $response->assertStatus(200);
});
