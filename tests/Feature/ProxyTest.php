<?php

use App\Models\AccessToken;
use App\Models\ApiKey;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiKey = ApiKey::create([
        'name' => 'Test Client',
        'key' => 'test-api-key',
        'is_active' => true,
    ]);

    $this->accessToken = AccessToken::create([
        'name' => 'Token 1',
        'token' => 'sk-ant-api03-test',
        'refresh_token' => 'test-refresh',
        'is_active' => true,
        'token_expires_at' => now()->addDay(),
        'usage_order' => 1,
    ]);
});

it('proxies a non-streaming request to Anthropic', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'model' => 'claude-sonnet-4-6',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ]),
    ]);

    $response = $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], ['x-api-key' => 'test-api-key']);

    $response->assertStatus(200)
        ->assertJsonPath('content.0.text', 'Hello!');
});

it('logs the request after proxying', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'model' => 'claude-sonnet-4-6',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], ['x-api-key' => 'test-api-key']);

    expect(RequestLog::count())->toBe(1);

    $log = RequestLog::first();
    expect($log->model)->toBe('claude-sonnet-4-6')
        ->and($log->input_tokens)->toBe(10)
        ->and($log->output_tokens)->toBe(5)
        ->and($log->status_code)->toBe(200)
        ->and($log->is_stream)->toBeFalse()
        ->and($log->api_key_id)->toBe($this->apiKey->id)
        ->and($log->access_token_id)->toBe($this->accessToken->id);
});

it('forwards the raw body without modification', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'msg_123', 'type' => 'message', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]]),
    ]);

    $rawBody = '{"model":"claude-sonnet-4-6","max_tokens":100,"messages":[{"role":"user","content":"Hi"}]}';

    $this->call('POST', '/v1/messages', [], [], [], [
        'HTTP_X_API_KEY' => 'test-api-key',
        'CONTENT_TYPE' => 'application/json',
    ], $rawBody);

    Http::assertSent(function ($request) use ($rawBody) {
        return $request->body() === $rawBody;
    });
});

it('forwards Anthropic error responses with correct status code', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens required'],
        ], 400),
    ]);

    $response = $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], ['x-api-key' => 'test-api-key']);

    $response->assertStatus(400)
        ->assertJsonPath('error.type', 'invalid_request_error');
});

it('retries with next access token on 429', function () {
    $token2 = AccessToken::create([
        'name' => 'Token 2',
        'token' => 'sk-ant-api03-test-2',
        'refresh_token' => 'test-refresh-2',
        'is_active' => true,
        'token_expires_at' => now()->addDay(),
        'usage_order' => 2,
    ]);

    Http::fakeSequence()
        ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error']], 429)
        ->push([
            'id' => 'msg_123',
            'type' => 'message',
            'content' => [['type' => 'text', 'text' => 'Success']],
            'model' => 'claude-sonnet-4-6',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200);

    $response = $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], ['x-api-key' => 'test-api-key']);

    $response->assertStatus(200)
        ->assertJsonPath('content.0.text', 'Success');
});

it('returns 401 without valid API key', function () {
    $response = $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ]);

    $response->assertStatus(401);
});

it('returns error when no access tokens available', function () {
    AccessToken::query()->delete();

    $response = $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], ['x-api-key' => 'test-api-key']);

    $response->assertStatus(503);
});

it('forwards anthropic-version and anthropic-beta headers', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'msg_123', 'type' => 'message', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]]),
    ]);

    $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], [
        'x-api-key' => 'test-api-key',
        'anthropic-version' => '2024-01-01',
        'anthropic-beta' => 'some-beta-feature',
    ]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('anthropic-version', '2024-01-01')
            && $request->hasHeader('authorization');
    });
});
