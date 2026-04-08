# Support Different Token Types — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Support both OAuth tokens (`sk-ant-oat*`) and standard API keys (`sk-ant-api03*`) in the same round-robin token pool, with type-appropriate proxy behavior.

**Architecture:** Add a `TokenType` enum and `type` column to `access_tokens`. ProxyService branches on token type for headers and body preparation. OAuth keeps current behavior; API keys get clean transparent proxy (x-api-key header, no body/header modifications).

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, SQLite

---

## Chunk 1: TokenType Enum + Migration + Model

### Task 1: Create TokenType Enum

**Files:**
- Create: `app/Enums/TokenType.php`

- [ ] **Step 1: Create the enum**

```php
<?php

namespace App\Enums;

enum TokenType: string
{
    case OAuth = 'oauth';
    case ApiKey = 'api_key';

    public static function detectFromToken(string $token): self
    {
        return str_starts_with($token, 'sk-ant-oat')
            ? self::OAuth
            : self::ApiKey;
    }
}
```

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/Enums/TokenType.php
git commit -m "feat: add TokenType enum with auto-detection"
```

### Task 2: Migration — Add `type` Column

**Files:**
- Create: `database/migrations/xxxx_add_type_to_access_tokens_table.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_type_to_access_tokens_table --no-interaction
```

- [ ] **Step 2: Write the migration**

```php
public function up(): void
{
    Schema::table('access_tokens', function (Blueprint $table) {
        $table->string('type')->default('oauth')->after('name');
    });

    // Backfill: mark any non-OAuth tokens as api_key
    DB::table('access_tokens')
        ->where('token', 'NOT LIKE', 'sk-ant-oat%')
        ->update(['type' => 'api_key']);
}

public function down(): void
{
    Schema::table('access_tokens', function (Blueprint $table) {
        $table->dropColumn('type');
    });
}
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*add_type_to_access_tokens_table.php
git commit -m "feat: add type column to access_tokens with backfill"
```

### Task 3: Update AccessToken Model

**Files:**
- Modify: `app/Models/AccessToken.php`
- Test: `tests/Unit/Models/AccessTokenTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Unit/Models/AccessTokenTest.php`:

```php
use App\Enums\TokenType;

it('casts type to TokenType enum', function () {
    $token = new AccessToken(['type' => 'oauth']);
    expect($token->type)->toBe(TokenType::OAuth);

    $token = new AccessToken(['type' => 'api_key']);
    expect($token->type)->toBe(TokenType::ApiKey);
});

it('detects oauth token type', function () {
    $token = new AccessToken(['type' => 'oauth']);
    expect($token->isOauth())->toBeTrue()
        ->and($token->isApiKey())->toBeFalse();
});

it('detects api key token type', function () {
    $token = new AccessToken(['type' => 'api_key']);
    expect($token->isOauth())->toBeFalse()
        ->and($token->isApiKey())->toBeTrue();
});

it('auto-detects type from token prefix on creation', function () {
    $oauth = AccessToken::create([
        'name' => 'OAuth',
        'token' => 'sk-ant-oat01-abc',
        'refresh_token' => 'sk-ant-ort01-abc',
    ]);
    expect($oauth->type)->toBe(TokenType::OAuth);

    $apiKey = AccessToken::create([
        'name' => 'API Key',
        'token' => 'sk-ant-api03-abc',
    ]);
    expect($apiKey->type)->toBe(TokenType::ApiKey);
});

it('api key tokens with null expiry are valid', function () {
    $token = new AccessToken([
        'type' => 'api_key',
        'is_active' => true,
        'token_expires_at' => null,
    ]);
    expect($token->isValid())->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=AccessTokenTest
```

- [ ] **Step 3: Update AccessToken model**

Update `app/Models/AccessToken.php`:
- Add `type` to `#[Fillable]` attribute
- Add `TokenType` enum cast
- Add `isOauth()` and `isApiKey()` helpers
- Add `booted()` with `creating` event to auto-detect type

```php
use App\Enums\TokenType;

#[Fillable(['name', 'type', 'token', 'refresh_token', 'token_expires_at', 'is_active', 'last_used_at', 'refresh_fail_count', 'usage_order'])]
class AccessToken extends Model
{
    protected function casts(): array
    {
        return [
            'type' => TokenType::class,
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AccessToken $token) {
            if (! $token->type) {
                $token->type = TokenType::detectFromToken($token->token);
            }
        });
    }

    public function isOauth(): bool
    {
        return $this->type === TokenType::OAuth;
    }

    public function isApiKey(): bool
    {
        return $this->type === TokenType::ApiKey;
    }

    // ... keep existing methods unchanged
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=AccessTokenTest
```

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Models/AccessToken.php tests/Unit/Models/AccessTokenTest.php
git commit -m "feat: add TokenType enum cast and auto-detection to AccessToken"
```

## Chunk 2: ProxyService — Type-Aware Headers and Body

### Task 4: Update ProxyService

**Files:**
- Modify: `app/Services/ProxyService.php`
- Test: `tests/Feature/ProxyTest.php`

- [ ] **Step 1: Write failing tests for API key proxy behavior**

Add to `tests/Feature/ProxyTest.php`:

```php
it('sends x-api-key header for API key tokens', function () {
    // Replace default OAuth token with API key token
    $this->accessToken->delete();
    $apiKeyToken = AccessToken::create([
        'name' => 'API Key Token',
        'token' => 'sk-ant-api03-test-key',
        'is_active' => true,
        'usage_order' => 1,
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'model' => 'claude-sonnet-4-6',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], ['x-api-key' => 'test-api-key']);

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-api-key', 'sk-ant-api03-test-key')
            && ! $request->hasHeader('authorization');
    });
});

it('does not modify body for API key tokens', function () {
    $this->accessToken->delete();
    AccessToken::create([
        'name' => 'API Key Token',
        'token' => 'sk-ant-api03-test-key',
        'is_active' => true,
        'usage_order' => 1,
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ]),
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

it('forwards client anthropic-beta as-is for API key tokens', function () {
    $this->accessToken->delete();
    AccessToken::create([
        'name' => 'API Key Token',
        'token' => 'sk-ant-api03-test-key',
        'is_active' => true,
        'usage_order' => 1,
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ]),
    ]);

    $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], [
        'x-api-key' => 'test-api-key',
        'anthropic-beta' => 'custom-beta-flag',
    ]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('anthropic-beta', 'custom-beta-flag');
    });
});

it('sends Authorization Bearer for OAuth tokens', function () {
    // Default token is sk-ant-api03-test — replace with OAuth
    $this->accessToken->delete();
    AccessToken::create([
        'name' => 'OAuth Token',
        'token' => 'sk-ant-oat01-test-oauth',
        'refresh_token' => 'sk-ant-ort01-test',
        'is_active' => true,
        'token_expires_at' => now()->addDay(),
        'usage_order' => 1,
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'model' => 'claude-sonnet-4-6',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], ['x-api-key' => 'test-api-key']);

    Http::assertSent(function ($request) {
        return $request->hasHeader('authorization', 'Bearer sk-ant-oat01-test-oauth')
            && $request->hasHeader('anthropic-beta')
            && str_contains($request->header('anthropic-beta')[0], 'oauth-2025-04-20');
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=ProxyTest
```

- [ ] **Step 3: Update `buildProxyHeaders` to accept AccessToken**

Change signature from `string $tokenValue` to `AccessToken $accessToken`. Branch logic by type:

```php
private function buildProxyHeaders(Request $request, AccessToken $accessToken): array
{
    $headers['content-type'] = 'application/json';

    if ($accessToken->isOauth()) {
        $headers['anthropic-version'] = $request->header('anthropic-version', config('airoxy.anthropic_version'));
        $headers['authorization'] = 'Bearer ' . $accessToken->token;

        $requiredBeta = 'claude-code-20250219,oauth-2025-04-20';
        $clientBeta = $request->header('anthropic-beta');
        if ($clientBeta) {
            $flags = array_unique(array_merge(
                explode(',', $requiredBeta),
                explode(',', $clientBeta),
            ));
            $headers['anthropic-beta'] = implode(',', $flags);
        } else {
            $headers['anthropic-beta'] = $requiredBeta;
        }

        $headers['user-agent'] = 'claude-cli/2.1.91 (external, cli)';
        $headers['x-app'] = 'cli';
    } else {
        $headers['x-api-key'] = $accessToken->token;

        if ($version = $request->header('anthropic-version')) {
            $headers['anthropic-version'] = $version;
        }

        if ($beta = $request->header('anthropic-beta')) {
            $headers['anthropic-beta'] = $beta;
        }
    }

    return $headers;
}
```

- [ ] **Step 4: Update `prepareRequestBody` to accept AccessToken**

Change signature from `string $tokenValue` to `AccessToken $accessToken`:

```php
private function prepareRequestBody(string $rawBody, array $parsed, AccessToken $accessToken): string
{
    if (! $accessToken->isOauth()) {
        return $rawBody;
    }

    // ... rest of existing OAuth system prompt injection logic unchanged,
    // but replace $tokenValue references with $accessToken->token
}
```

- [ ] **Step 5: Update `handle()` method calls**

Replace the two call sites in `handle()`:
```php
$proxyHeaders = $this->buildProxyHeaders($request, $accessToken);
$forwardBody = $this->prepareRequestBody($rawBody, $parsed, $accessToken);
```

- [ ] **Step 6: Run NEW tests only (existing tests may fail until Task 8 fixes them)**

```bash
php artisan test --compact --filter="sends x-api-key|does not modify body for API key|forwards client anthropic-beta|sends Authorization Bearer"
```

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```bash
git add app/Services/ProxyService.php tests/Feature/ProxyTest.php
git commit -m "feat: type-aware proxy headers and body preparation"
```

## Chunk 3: CLI Commands + TokenRefresher

### Task 5: Update TokenAddCommand

**Files:**
- Modify: `app/Console/Commands/TokenAddCommand.php`
- Test: `tests/Feature/Commands/TokenCommandsTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/Commands/TokenCommandsTest.php`:

```php
use App\Enums\TokenType;

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
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=TokenCommandsTest
```

- [ ] **Step 3: Update TokenAddCommand**

```php
protected $signature = 'airoxy:token:add {token} {refresh_token?} {--name=} {--expires-in=28800}';

public function handle(): int
{
    $tokenValue = $this->argument('token');

    if (! str_starts_with($tokenValue, 'sk-ant-')) {
        $this->error('Invalid token format. Token must start with sk-ant-');
        return self::FAILURE;
    }

    $isOauth = str_starts_with($tokenValue, 'sk-ant-oat');
    $refreshToken = $this->argument('refresh_token');

    if ($isOauth && ! $refreshToken) {
        $this->error('OAuth tokens require a refresh_token argument.');
        return self::FAILURE;
    }

    $data = [
        'name' => $this->option('name'),
        'token' => $tokenValue,
    ];

    if ($isOauth) {
        $data['refresh_token'] = $refreshToken;
        $data['token_expires_at'] = now()->addSeconds((int) $this->option('expires-in'));
    }

    $token = AccessToken::create($data);

    $this->info("Access token added successfully (ID: {$token->id}, Type: {$token->type->value}).");

    return self::SUCCESS;
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=TokenCommandsTest
```

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/TokenAddCommand.php tests/Feature/Commands/TokenCommandsTest.php
git commit -m "feat: token:add supports API keys without refresh_token"
```

### Task 6: Update TokenListCommand

**Files:**
- Modify: `app/Console/Commands/TokenListCommand.php`

- [ ] **Step 1: Add Type column to table output**

Update the `$this->table()` call:

```php
$this->table(
    ['ID', 'Name', 'Type', 'Token', 'Active', 'Expires', 'Fails', 'Last Used'],
    $tokens->map(fn (AccessToken $token) => [
        $token->id,
        $token->name ?? '-',
        $token->type->value,
        $token->masked_token,
        $token->is_active ? 'Yes' : 'No',
        $token->token_expires_at?->diffForHumans() ?? '-',
        $token->refresh_fail_count,
        $token->last_used_at?->diffForHumans() ?? 'Never',
    ]),
);
```

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/TokenListCommand.php
git commit -m "feat: show token type in token:list output"
```

### Task 7: Update TokenRefresher + TokenRefreshCommand

**Files:**
- Modify: `app/Services/TokenRefresher.php`
- Modify: `app/Console/Commands/TokenRefreshCommand.php`
- Test: `tests/Feature/Services/TokenRefresherTest.php`
- Test: `tests/Feature/Commands/TokenCommandsTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/Services/TokenRefresherTest.php`:

```php
it('skips API key tokens in refreshAll', function () {
    AccessToken::create([
        'name' => 'API Key',
        'token' => 'sk-ant-api03-test',
        'is_active' => true,
    ]);

    $refresher = new TokenRefresher;
    $result = $refresher->refreshAll();

    expect($result['refreshed'])->toBe(0)
        ->and($result['failed'])->toBe(0);
});
```

Add to `tests/Feature/Commands/TokenCommandsTest.php`:

```php
it('skips refresh for API key tokens', function () {
    $token = AccessToken::create([
        'name' => 'API Key',
        'token' => 'sk-ant-api03-test',
        'is_active' => true,
    ]);

    $this->artisan('airoxy:token:refresh', ['--id' => $token->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('not an OAuth token');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter="TokenRefresherTest|TokenCommandsTest"
```

- [ ] **Step 3: Update TokenRefresher::refreshAll()**

Add `where('type', 'oauth')` filter:

```php
public function refreshAll(): array
{
    $tokens = AccessToken::where('is_active', true)
        ->where('type', 'oauth')
        ->whereNotNull('token_expires_at')
        ->get();
    // ... rest unchanged
}
```

- [ ] **Step 4: Update TokenRefreshCommand::handle()**

Add guard for API key tokens when `--id` is used:

```php
if ($id = $this->option('id')) {
    $token = AccessToken::find($id);

    if (! $token) {
        $this->error('Token not found.');
        return self::FAILURE;
    }

    if ($token->isApiKey()) {
        $this->warn("Token ID {$id} is not an OAuth token — skipping refresh.");
        return self::SUCCESS;
    }

    // ... rest unchanged
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter="TokenRefresherTest|TokenCommandsTest"
```

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Services/TokenRefresher.php app/Console/Commands/TokenRefreshCommand.php tests/Feature/Services/TokenRefresherTest.php tests/Feature/Commands/TokenCommandsTest.php
git commit -m "feat: skip API key tokens in refresh flow"
```

## Chunk 4: Fix Existing Tests + Final Verification

### Task 8: Fix Existing Tests

Multiple test files use non-`sk-ant-` prefixed tokens or `sk-ant-api03-test` tokens that will now be auto-detected as `api_key` type. Tests must be updated to use correct prefixes matching their intended behavior.

**Files:**
- Modify: `tests/Feature/ProxyTest.php`
- Modify: `tests/Feature/Commands/TokenCommandsTest.php`
- Modify: `tests/Feature/Services/TokenRefresherTest.php`

- [ ] **Step 1: Update ProxyTest `beforeEach` to use OAuth token**

```php
$this->accessToken = AccessToken::create([
    'name' => 'Token 1',
    'token' => 'sk-ant-oat01-test',
    'refresh_token' => 'sk-ant-ort01-test',
    'is_active' => true,
    'token_expires_at' => now()->addDay(),
    'usage_order' => 1,
]);
```

- [ ] **Step 2: Update ProxyTest "forwards the raw body without modification" test**

This test will now fail because the default token is OAuth and `prepareRequestBody` will inject the system prompt. Change it to use an API key token where body is guaranteed unmodified:

```php
it('forwards the raw body without modification for API key tokens', function () {
    $this->accessToken->delete();
    AccessToken::create([
        'name' => 'API Key Token',
        'token' => 'sk-ant-api03-test-key',
        'is_active' => true,
        'usage_order' => 1,
    ]);

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
```

- [ ] **Step 3: Update 429 retry test's second token to use OAuth prefix**

```php
$token2 = AccessToken::create([
    'name' => 'Token 2',
    'token' => 'sk-ant-oat01-test-2',
    'refresh_token' => 'sk-ant-ort01-test-2',
    'is_active' => true,
    'token_expires_at' => now()->addDay(),
    'usage_order' => 2,
]);
```

- [ ] **Step 4: Update TokenCommandsTest tokens to use proper prefixes**

Update "removes a token by ID" test: change `'token' => 'test-token'` to `'token' => 'sk-ant-api03-test-token'` (and remove `'refresh_token'`).

Update "refreshes a specific token" test: change `'token' => 'old-token'` to `'token' => 'sk-ant-oat01-old-token'`.

Update "refreshes all active tokens" test: change `'token' => 'old-1'` to `'token' => 'sk-ant-oat01-old-1'` and `'token' => 'old-2'` to `'token' => 'sk-ant-oat01-old-2'`. Add `'token_expires_at' => now()->addDay()` to both so `refreshAll()` picks them up.

- [ ] **Step 5: Update TokenRefresherTest tokens to use proper prefixes**

"increments fail count" and "deactivates after 3 failures": change `'token' => 'old-token'` to `'token' => 'sk-ant-oat01-old-token'`.

- [ ] **Step 6: Run full test suite**

```bash
php artisan test --compact
```

- [ ] **Step 7: Fix any remaining test failures**

- [ ] **Step 8: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 9: Commit**

```bash
git add tests/
git commit -m "test: update existing tests for token type awareness"
```

### Task 9: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
php artisan test --compact
```

All tests must pass.

- [ ] **Step 2: Run Pint on all PHP files**

```bash
vendor/bin/pint --dirty --format agent
```

No formatting issues.