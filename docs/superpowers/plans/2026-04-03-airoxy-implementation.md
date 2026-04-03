# Airoxy Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a transparent Anthropic API proxy server with round-robin key rotation, OAuth2 token management, streaming SSE support, and CLI administration.

**Architecture:** Laravel 13 + Octane/FrankenPHP server. Single `POST /v1/messages` endpoint proxies requests byte-for-byte to Anthropic. SQLite database stores API keys (encrypted), access tokens, request logs, and aggregated daily stats. All management via Artisan CLI commands exposed through a global `airoxy` alias.

**Tech Stack:** PHP 8.5, Laravel 13, Laravel Octane + FrankenPHP, SQLite, Pest 4, Guzzle (via Laravel HTTP client)

**Spec:** `docs/superpowers/specs/2026-04-03-airoxy-design.md`

---

## Chunk 1: Foundation — Config, Database, Models

### Task 1: Project configuration and dependencies

**Files:**
- Modify: `composer.json`
- Modify: `config/database.php`
- Modify: `.env`
- Modify: `.env.example`
- Create: `config/airoxy.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Update composer.json PHP requirement**

In `composer.json`, change `"php": "^8.3"` to `"php": "^8.5"`.

- [ ] **Step 2: Install laravel/octane**

Run: `composer require laravel/octane --no-interaction`

- [ ] **Step 3: Install Octane with FrankenPHP**

Run: `php artisan octane:install --server=frankenphp --no-interaction`

- [ ] **Step 4: Configure SQLite busy_timeout**

In `config/database.php`, change the SQLite `busy_timeout` from `null` to `5000`:

```php
'busy_timeout' => 5000,
```

- [ ] **Step 5: Create config/airoxy.php**

```php
<?php

return [

    'host' => env('AIROXY_HOST', '0.0.0.0'),
    'port' => env('AIROXY_PORT', 3800),

    'anthropic_api_url' => 'https://api.anthropic.com/v1/messages',
    'anthropic_version' => '2023-06-01',

    'token_refresh_interval' => env('AIROXY_TOKEN_REFRESH_INTERVAL', 360),
    'log_retention_days' => env('AIROXY_LOG_RETENTION_DAYS', 3),

    'oauth' => [
        'endpoint' => 'https://platform.claude.com/v1/oauth/token',
        'client_id' => '9d1c250a-e61b-44d9-88ed-5944d1962f5e',
        'scope' => 'user:profile user:inference',
    ],

    'pricing' => [
        'claude-opus-4-6'            => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-6'          => ['input' => 3.00,  'output' => 15.00],
        'claude-haiku-4-5'           => ['input' => 0.80,  'output' => 4.00],
        'claude-haiku-4-5-20251001'  => ['input' => 0.80,  'output' => 4.00],
        'claude-opus-4-5'            => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-5-20251101'   => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-5'          => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4-5-20250929' => ['input' => 3.00,  'output' => 15.00],
        'claude-opus-4-1'            => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-1-20250805'   => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-0'            => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-20250514'     => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-0'          => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4-20250514'   => ['input' => 3.00,  'output' => 15.00],
        'claude-3-haiku-20240307'    => ['input' => 0.25,  'output' => 1.25],

        'cache_write_multiplier' => 1.25,
        'cache_read_multiplier'  => 0.10,

        'default' => ['input' => 3.00, 'output' => 15.00],
    ],
];
```

- [ ] **Step 6: Update .env and .env.example**

Add to both files:

```env
# Airoxy
AIROXY_HOST=0.0.0.0
AIROXY_PORT=3800
AIROXY_TOKEN_REFRESH_INTERVAL=360
AIROXY_LOG_RETENTION_DAYS=3
```

Change `APP_NAME=Laravel` to `APP_NAME=Airoxy` in `.env`.

- [ ] **Step 7: Add API routing to bootstrap/app.php**

Add `api: __DIR__.'/../routes/api.php'` to the `withRouting` call, set `apiPrefix` to empty string (so routes are at `/v1/messages` not `/api/v1/messages`), and change the health route to `/health`:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
```

- [ ] **Step 8: Create routes/api.php**

```php
<?php

use Illuminate\Support\Facades\Route;

// Proxy route will be added in Task 7
```

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: add project configuration, Octane, and airoxy config"
```

---

### Task 2: Database migrations

**Files:**
- Create: `database/migrations/2026_04_03_000001_create_anthropic_api_keys_table.php`
- Create: `database/migrations/2026_04_03_000002_create_access_tokens_table.php`
- Create: `database/migrations/2026_04_03_000003_create_request_logs_table.php`
- Create: `database/migrations/2026_04_03_000004_create_daily_stats_table.php`

- [ ] **Step 1: Create anthropic_api_keys migration**

Run: `php artisan make:migration create_anthropic_api_keys_table --no-interaction`

Edit the generated file:

```php
public function up(): void
{
    Schema::create('anthropic_api_keys', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->text('api_key'); // encrypted via model cast
        $table->boolean('is_active')->default(true);
        $table->timestamp('last_used_at')->nullable();
        $table->integer('usage_order')->default(0);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('anthropic_api_keys');
}
```

- [ ] **Step 2: Create access_tokens migration**

Run: `php artisan make:migration create_access_tokens_table --no-interaction`

```php
public function up(): void
{
    Schema::create('access_tokens', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->text('token')->unique();
        $table->text('refresh_token');
        $table->timestamp('token_expires_at')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamp('last_used_at')->nullable();
        $table->integer('refresh_fail_count')->default(0);
        $table->timestamps();

        $table->index('is_active');
    });
}

public function down(): void
{
    Schema::dropIfExists('access_tokens');
}
```

- [ ] **Step 3: Create request_logs migration**

Run: `php artisan make:migration create_request_logs_table --no-interaction`

```php
public function up(): void
{
    Schema::create('request_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('access_token_id')->nullable()->constrained('access_tokens')->nullOnDelete();
        $table->foreignId('api_key_id')->nullable()->constrained('anthropic_api_keys')->nullOnDelete();
        $table->string('model');
        $table->string('endpoint')->default('/v1/messages');
        $table->boolean('is_stream')->default(false);
        $table->integer('input_tokens')->nullable();
        $table->integer('output_tokens')->nullable();
        $table->integer('cache_creation_input_tokens')->nullable();
        $table->integer('cache_read_input_tokens')->nullable();
        $table->decimal('estimated_cost_usd', 10, 8)->nullable();
        $table->integer('status_code');
        $table->text('error_message')->nullable();
        $table->integer('duration_ms');
        $table->timestamp('requested_at');
        $table->timestamp('created_at')->nullable();

        $table->index('requested_at');
        $table->index('access_token_id');
    });
}

public function down(): void
{
    Schema::dropIfExists('request_logs');
}
```

- [ ] **Step 4: Create daily_stats migration**

Run: `php artisan make:migration create_daily_stats_table --no-interaction`

```php
public function up(): void
{
    Schema::create('daily_stats', function (Blueprint $table) {
        $table->id();
        $table->date('date');
        $table->foreignId('access_token_id')->nullable()->constrained('access_tokens')->nullOnDelete();
        $table->string('model')->nullable();
        $table->integer('total_requests')->default(0);
        $table->integer('successful_requests')->default(0);
        $table->integer('failed_requests')->default(0);
        $table->bigInteger('total_input_tokens')->default(0);
        $table->bigInteger('total_output_tokens')->default(0);
        $table->bigInteger('total_cache_creation_tokens')->default(0);
        $table->bigInteger('total_cache_read_tokens')->default(0);
        $table->decimal('total_estimated_cost_usd', 12, 8)->default(0);
        $table->timestamps();

        $table->unique(['date', 'access_token_id', 'model']);
        $table->index('date');
    });
}

public function down(): void
{
    Schema::dropIfExists('daily_stats');
}
```

- [ ] **Step 5: Run migrations**

Run: `php artisan migrate --no-interaction`
Expected: All 4 tables created successfully.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/
git commit -m "feat: add database migrations for api_keys, access_tokens, request_logs, daily_stats"
```

---

### Task 3: Eloquent models

**Files:**
- Create: `app/Models/AnthropicApiKey.php`
- Create: `app/Models/AccessToken.php`
- Create: `app/Models/RequestLog.php`
- Create: `app/Models/DailyStat.php`
- Test: `tests/Unit/Models/AnthropicApiKeyTest.php`
- Test: `tests/Unit/Models/AccessTokenTest.php`

- [ ] **Step 1: Create AnthropicApiKey model**

Run: `php artisan make:model AnthropicApiKey --no-interaction`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'api_key', 'is_active', 'last_used_at', 'usage_order'])]
class AnthropicApiKey extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RequestLog, $this>
     */
    public function requestLogs(): HasMany
    {
        return $this->hasMany(RequestLog::class, 'api_key_id');
    }

    /**
     * Get a masked version of the API key for display (last 8 chars visible).
     */
    public function getMaskedKeyAttribute(): string
    {
        $key = $this->api_key;

        if (strlen($key) <= 8) {
            return str_repeat('*', strlen($key));
        }

        return str_repeat('*', strlen($key) - 8) . substr($key, -8);
    }
}
```

- [ ] **Step 2: Create AccessToken model**

Run: `php artisan make:model AccessToken --no-interaction`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'token', 'refresh_token', 'token_expires_at', 'is_active', 'last_used_at', 'refresh_fail_count'])]
class AccessToken extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RequestLog, $this>
     */
    public function requestLogs(): HasMany
    {
        return $this->hasMany(RequestLog::class);
    }

    /**
     * @return HasMany<DailyStat, $this>
     */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(DailyStat::class);
    }

    /**
     * Check if the token has expired.
     */
    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * Check if the token is valid (active and not expired).
     */
    public function isValid(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    /**
     * Get a masked version of the token for display.
     */
    public function getMaskedTokenAttribute(): string
    {
        $token = $this->token;

        if (strlen($token) <= 12) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 8) . str_repeat('*', strlen($token) - 12) . substr($token, -4);
    }
}
```

- [ ] **Step 3: Create RequestLog model**

Run: `php artisan make:model RequestLog --no-interaction`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'access_token_id',
        'api_key_id',
        'model',
        'endpoint',
        'is_stream',
        'input_tokens',
        'output_tokens',
        'cache_creation_input_tokens',
        'cache_read_input_tokens',
        'estimated_cost_usd',
        'status_code',
        'error_message',
        'duration_ms',
        'requested_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_stream' => 'boolean',
            'estimated_cost_usd' => 'decimal:8',
            'requested_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AccessToken, $this>
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(AccessToken::class);
    }

    /**
     * @return BelongsTo<AnthropicApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(AnthropicApiKey::class, 'api_key_id');
    }
}
```

- [ ] **Step 4: Create DailyStat model**

Run: `php artisan make:model DailyStat --no-interaction`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStat extends Model
{
    protected $fillable = [
        'date',
        'access_token_id',
        'model',
        'total_requests',
        'successful_requests',
        'failed_requests',
        'total_input_tokens',
        'total_output_tokens',
        'total_cache_creation_tokens',
        'total_cache_read_tokens',
        'total_estimated_cost_usd',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_estimated_cost_usd' => 'decimal:8',
        ];
    }

    /**
     * @return BelongsTo<AccessToken, $this>
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(AccessToken::class);
    }
}
```

- [ ] **Step 5: Write model tests**

Create `tests/Unit/Models/AnthropicApiKeyTest.php`:

```php
<?php

use App\Models\AnthropicApiKey;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('encrypts api_key when storing', function () {
    $key = AnthropicApiKey::create([
        'name' => 'Test Key',
        'api_key' => 'sk-ant-api03-test-key-value',
        'usage_order' => 1,
    ]);

    // Raw DB value should not match plaintext
    $raw = DB::table('anthropic_api_keys')->where('id', $key->id)->value('api_key');
    expect($raw)->not->toBe('sk-ant-api03-test-key-value');

    // Model should decrypt correctly
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
```

Create `tests/Unit/Models/AccessTokenTest.php`:

```php
<?php

use App\Models\AccessToken;

it('detects expired tokens', function () {
    $token = new AccessToken(['token_expires_at' => now()->subHour()]);
    expect($token->isExpired())->toBeTrue();

    $token = new AccessToken(['token_expires_at' => now()->addHour()]);
    expect($token->isExpired())->toBeFalse();
});

it('validates token is active and not expired', function () {
    $valid = new AccessToken([
        'is_active' => true,
        'token_expires_at' => now()->addHour(),
    ]);
    expect($valid->isValid())->toBeTrue();

    $inactive = new AccessToken([
        'is_active' => false,
        'token_expires_at' => now()->addHour(),
    ]);
    expect($inactive->isValid())->toBeFalse();

    $expired = new AccessToken([
        'is_active' => true,
        'token_expires_at' => now()->subHour(),
    ]);
    expect($expired->isValid())->toBeFalse();
});

it('masks the token for display', function () {
    $token = new AccessToken(['token' => 'sk-ant-oat01-abcdefghijklmnop']);
    $masked = $token->masked_token;

    expect($masked)
        ->toStartWith('sk-ant-o')
        ->toEndWith('mnop')
        ->toContain('***');
});
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact --filter=Models`
Expected: All tests pass.

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Models/ tests/Unit/Models/
git commit -m "feat: add Eloquent models with encrypted API keys and token validation"
```

---

## Chunk 2: Core Services

### Task 4: CostCalculator service

**Files:**
- Create: `app/Services/CostCalculator.php`
- Test: `tests/Unit/Services/CostCalculatorTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Services/CostCalculatorTest.php`:

```php
<?php

use App\Services\CostCalculator;

it('calculates cost for known model', function () {
    $calculator = new CostCalculator;

    $cost = $calculator->calculate(
        model: 'claude-sonnet-4-6',
        inputTokens: 1000,
        outputTokens: 500,
    );

    // input: 1000/1M * 3.00 = 0.003
    // output: 500/1M * 15.00 = 0.0075
    expect($cost)->toBe(0.0105);
});

it('calculates cost with cache tokens', function () {
    $calculator = new CostCalculator;

    $cost = $calculator->calculate(
        model: 'claude-sonnet-4-6',
        inputTokens: 1000,
        outputTokens: 500,
        cacheCreationInputTokens: 2000,
        cacheReadInputTokens: 5000,
    );

    // input: 1000/1M * 3.00 = 0.003
    // output: 500/1M * 15.00 = 0.0075
    // cache write: 2000/1M * 3.00 * 1.25 = 0.0075
    // cache read: 5000/1M * 3.00 * 0.10 = 0.0015
    expect($cost)->toBe(0.0195);
});

it('uses default pricing for unknown model', function () {
    $calculator = new CostCalculator;

    $cost = $calculator->calculate(
        model: 'claude-unknown-model',
        inputTokens: 1_000_000,
        outputTokens: 0,
    );

    // default input: 1M/1M * 3.00 = 3.00
    expect($cost)->toBe(3.0);
});

it('returns zero for null tokens', function () {
    $calculator = new CostCalculator;
    $cost = $calculator->calculate('claude-sonnet-4-6');
    expect($cost)->toBe(0.0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=CostCalculator`
Expected: FAIL

- [ ] **Step 3: Implement CostCalculator**

Create `app/Services/CostCalculator.php`:

```php
<?php

namespace App\Services;

class CostCalculator
{
    public function calculate(
        string $model,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $cacheCreationInputTokens = null,
        ?int $cacheReadInputTokens = null,
    ): float {
        $pricing = config('airoxy.pricing');
        $modelPricing = $pricing[$model] ?? $pricing['default'];

        $inputPrice = $modelPricing['input'];
        $outputPrice = $modelPricing['output'];
        $cacheWriteMultiplier = $pricing['cache_write_multiplier'];
        $cacheReadMultiplier = $pricing['cache_read_multiplier'];

        $inputCost = ($inputTokens ?? 0) / 1_000_000 * $inputPrice;
        $outputCost = ($outputTokens ?? 0) / 1_000_000 * $outputPrice;
        $cacheWriteCost = ($cacheCreationInputTokens ?? 0) / 1_000_000 * $inputPrice * $cacheWriteMultiplier;
        $cacheReadCost = ($cacheReadInputTokens ?? 0) / 1_000_000 * $inputPrice * $cacheReadMultiplier;

        return $inputCost + $outputCost + $cacheWriteCost + $cacheReadCost;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CostCalculator`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/CostCalculator.php tests/Unit/Services/CostCalculatorTest.php
git commit -m "feat: add CostCalculator service with pricing config"
```

---

### Task 5: ApiKeyRotator service

**Files:**
- Create: `app/Services/ApiKeyRotator.php`
- Test: `tests/Unit/Services/ApiKeyRotatorTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Services/ApiKeyRotatorTest.php`:

```php
<?php

use App\Models\AnthropicApiKey;
use App\Services\ApiKeyRotator;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('selects the least recently used active key', function () {
    AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'key-1', 'usage_order' => 1, 'last_used_at' => now()->subMinutes(10)]);
    AnthropicApiKey::create(['name' => 'Key 2', 'api_key' => 'key-2', 'usage_order' => 2, 'last_used_at' => now()->subMinutes(20)]);
    AnthropicApiKey::create(['name' => 'Key 3', 'api_key' => 'key-3', 'usage_order' => 3, 'last_used_at' => now()->subMinutes(5)]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();

    expect($key->name)->toBe('Key 2'); // oldest last_used_at
});

it('prefers keys with null last_used_at', function () {
    AnthropicApiKey::create(['name' => 'Used', 'api_key' => 'key-1', 'usage_order' => 1, 'last_used_at' => now()]);
    AnthropicApiKey::create(['name' => 'Never Used', 'api_key' => 'key-2', 'usage_order' => 2, 'last_used_at' => null]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();

    expect($key->name)->toBe('Never Used');
});

it('uses usage_order as tiebreaker', function () {
    AnthropicApiKey::create(['name' => 'Order 2', 'api_key' => 'key-1', 'usage_order' => 2, 'last_used_at' => null]);
    AnthropicApiKey::create(['name' => 'Order 1', 'api_key' => 'key-2', 'usage_order' => 1, 'last_used_at' => null]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();

    expect($key->name)->toBe('Order 1');
});

it('skips inactive keys', function () {
    AnthropicApiKey::create(['name' => 'Inactive', 'api_key' => 'key-1', 'usage_order' => 1, 'is_active' => false]);
    AnthropicApiKey::create(['name' => 'Active', 'api_key' => 'key-2', 'usage_order' => 2, 'is_active' => true]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();

    expect($key->name)->toBe('Active');
});

it('skips excluded key IDs', function () {
    AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'key-1', 'usage_order' => 1]);
    $key2 = AnthropicApiKey::create(['name' => 'Key 2', 'api_key' => 'key-2', 'usage_order' => 2]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext(excludeIds: [1]);

    expect($key->name)->toBe('Key 2');
});

it('returns null when no keys available', function () {
    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();

    expect($key)->toBeNull();
});

it('updates last_used_at after selection', function () {
    AnthropicApiKey::create(['name' => 'Key 1', 'api_key' => 'key-1', 'usage_order' => 1, 'last_used_at' => null]);

    $rotator = new ApiKeyRotator;
    $key = $rotator->selectNext();

    expect($key->last_used_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ApiKeyRotator`
Expected: FAIL

- [ ] **Step 3: Implement ApiKeyRotator**

Create `app/Services/ApiKeyRotator.php`:

```php
<?php

namespace App\Services;

use App\Models\AnthropicApiKey;
use Illuminate\Support\Facades\DB;

class ApiKeyRotator
{
    /**
     * Select the next available API key using round-robin.
     *
     * @param  array<int>  $excludeIds
     */
    public function selectNext(array $excludeIds = []): ?AnthropicApiKey
    {
        return DB::transaction(function () use ($excludeIds) {
            $query = AnthropicApiKey::where('is_active', true)
                ->orderByRaw('last_used_at IS NOT NULL, last_used_at ASC')
                ->orderBy('usage_order');

            if ($excludeIds) {
                $query->whereNotIn('id', $excludeIds);
            }

            $key = $query->first();

            if ($key) {
                $key->update(['last_used_at' => now()]);
            }

            return $key;
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ApiKeyRotator`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ApiKeyRotator.php tests/Unit/Services/ApiKeyRotatorTest.php
git commit -m "feat: add ApiKeyRotator service with round-robin selection"
```

---

### Task 6: StreamHandler service

**Files:**
- Create: `app/Services/StreamHandler.php`
- Test: `tests/Unit/Services/StreamHandlerTest.php`

- [ ] **Step 1: Write failing tests for SSE parsing**

Create `tests/Unit/Services/StreamHandlerTest.php`:

```php
<?php

use App\Services\StreamHandler;

it('parses input tokens from message_start event', function () {
    $handler = new StreamHandler;

    $chunk = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":25,\"cache_creation_input_tokens\":100,\"cache_read_input_tokens\":200}}}\n\n";

    $handler->parseSSEChunk($chunk);

    expect($handler->getInputTokens())->toBe(25)
        ->and($handler->getCacheCreationInputTokens())->toBe(100)
        ->and($handler->getCacheReadInputTokens())->toBe(200);
});

it('parses output tokens from message_delta event', function () {
    $handler = new StreamHandler;

    $chunk = "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":42}}\n\n";

    $handler->parseSSEChunk($chunk);

    expect($handler->getOutputTokens())->toBe(42);
});

it('handles chunks split across event boundaries', function () {
    $handler = new StreamHandler;

    // First chunk: incomplete event
    $handler->parseSSEChunk("event: message_start\ndata: {\"type\":\"message_start\",\"mes");
    expect($handler->getInputTokens())->toBeNull();

    // Second chunk: completes the event
    $handler->parseSSEChunk("sage\":{\"usage\":{\"input_tokens\":50}}}\n\n");
    expect($handler->getInputTokens())->toBe(50);
});

it('handles multiple events in a single chunk', function () {
    $handler = new StreamHandler;

    $chunk = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":10}}}\n\nevent: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{},\"usage\":{\"output_tokens\":20}}\n\n";

    $handler->parseSSEChunk($chunk);

    expect($handler->getInputTokens())->toBe(10)
        ->and($handler->getOutputTokens())->toBe(20);
});

it('ignores non-usage events', function () {
    $handler = new StreamHandler;

    $chunk = "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n";

    $handler->parseSSEChunk($chunk);

    expect($handler->getInputTokens())->toBeNull()
        ->and($handler->getOutputTokens())->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=StreamHandler`
Expected: FAIL

- [ ] **Step 3: Implement StreamHandler**

Create `app/Services/StreamHandler.php`:

```php
<?php

namespace App\Services;

class StreamHandler
{
    private ?int $inputTokens = null;

    private ?int $outputTokens = null;

    private ?int $cacheCreationInputTokens = null;

    private ?int $cacheReadInputTokens = null;

    private string $buffer = '';

    public function parseSSEChunk(string $chunk): void
    {
        $this->buffer .= $chunk;

        // SSE events are separated by double newline
        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $event = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);

            $this->parseEvent($event);
        }
    }

    private function parseEvent(string $event): void
    {
        $dataLine = null;

        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $dataLine = substr($line, 6);
            }
        }

        if ($dataLine === null) {
            return;
        }

        $data = json_decode($dataLine, true);

        if (! is_array($data)) {
            return;
        }

        match ($data['type'] ?? null) {
            'message_start' => $this->parseMessageStart($data),
            'message_delta' => $this->parseMessageDelta($data),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parseMessageStart(array $data): void
    {
        $usage = $data['message']['usage'] ?? [];

        $this->inputTokens = $usage['input_tokens'] ?? null;
        $this->cacheCreationInputTokens = $usage['cache_creation_input_tokens'] ?? null;
        $this->cacheReadInputTokens = $usage['cache_read_input_tokens'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parseMessageDelta(array $data): void
    {
        $this->outputTokens = $data['usage']['output_tokens'] ?? null;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function getCacheCreationInputTokens(): ?int
    {
        return $this->cacheCreationInputTokens;
    }

    public function getCacheReadInputTokens(): ?int
    {
        return $this->cacheReadInputTokens;
    }

    public function reset(): void
    {
        $this->inputTokens = null;
        $this->outputTokens = null;
        $this->cacheCreationInputTokens = null;
        $this->cacheReadInputTokens = null;
        $this->buffer = '';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=StreamHandler`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/StreamHandler.php tests/Unit/Services/StreamHandlerTest.php
git commit -m "feat: add StreamHandler for SSE token parsing"
```

---

### Task 7: TokenRefresher service

**Files:**
- Create: `app/Services/TokenRefresher.php`
- Test: `tests/Feature/Services/TokenRefresherTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Services/TokenRefresherTest.php`:

```php
<?php

use App\Models\AccessToken;
use App\Services\TokenRefresher;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('refreshes a token successfully', function () {
    Http::fake([
        'platform.claude.com/*' => Http::response([
            'token_type' => 'Bearer',
            'access_token' => 'sk-ant-oat01-new-token',
            'expires_in' => 28800,
            'refresh_token' => 'sk-ant-ort01-new-refresh',
            'scope' => 'user:profile user:inference',
        ]),
    ]);

    $token = AccessToken::create([
        'name' => 'Test',
        'token' => 'sk-ant-oat01-old-token',
        'refresh_token' => 'sk-ant-ort01-old-refresh',
        'token_expires_at' => now()->subHour(),
        'refresh_fail_count' => 1,
    ]);

    $refresher = new TokenRefresher;
    $result = $refresher->refresh($token);

    expect($result)->toBeTrue();

    $token->refresh();
    expect($token->token)->toBe('sk-ant-oat01-new-token')
        ->and($token->refresh_token)->toBe('sk-ant-ort01-new-refresh')
        ->and($token->refresh_fail_count)->toBe(0)
        ->and($token->token_expires_at)->toBeGreaterThan(now());
});

it('increments fail count on refresh failure', function () {
    Http::fake([
        'platform.claude.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $token = AccessToken::create([
        'name' => 'Test',
        'token' => 'old-token',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => now()->subHour(),
        'refresh_fail_count' => 0,
    ]);

    $refresher = new TokenRefresher;
    $result = $refresher->refresh($token);

    expect($result)->toBeFalse();

    $token->refresh();
    expect($token->refresh_fail_count)->toBe(1)
        ->and($token->is_active)->toBeTrue();
});

it('deactivates token after 3 consecutive failures', function () {
    Http::fake([
        'platform.claude.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $token = AccessToken::create([
        'name' => 'Test',
        'token' => 'old-token',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => now()->subHour(),
        'refresh_fail_count' => 2,
    ]);

    $refresher = new TokenRefresher;
    $refresher->refresh($token);

    $token->refresh();
    expect($token->refresh_fail_count)->toBe(3)
        ->and($token->is_active)->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=TokenRefresher`
Expected: FAIL

- [ ] **Step 3: Implement TokenRefresher**

Create `app/Services/TokenRefresher.php`:

```php
<?php

namespace App\Services;

use App\Models\AccessToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokenRefresher
{
    public function refresh(AccessToken $token): bool
    {
        try {
            $response = Http::post(config('airoxy.oauth.endpoint'), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->refresh_token,
                'client_id' => config('airoxy.oauth.client_id'),
                'scope' => config('airoxy.oauth.scope'),
            ]);

            if ($response->failed()) {
                return $this->handleFailure($token, 'HTTP ' . $response->status());
            }

            $data = $response->json();

            $token->update([
                'token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in']),
                'refresh_fail_count' => 0,
            ]);

            return true;
        } catch (\Throwable $e) {
            return $this->handleFailure($token, $e->getMessage());
        }
    }

    private function handleFailure(AccessToken $token, string $reason): bool
    {
        $failCount = $token->refresh_fail_count + 1;

        $updates = ['refresh_fail_count' => $failCount];

        if ($failCount >= 3) {
            $updates['is_active'] = false;
            Log::warning("Access token '{$token->name}' (ID: {$token->id}) deactivated after {$failCount} consecutive refresh failures.");
        }

        $token->update($updates);

        Log::error("Token refresh failed for '{$token->name}' (ID: {$token->id}): {$reason}");

        return false;
    }

    /**
     * Refresh all active tokens.
     *
     * @return array{refreshed: int, failed: int}
     */
    public function refreshAll(): array
    {
        $tokens = AccessToken::where('is_active', true)->get();
        $refreshed = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            if ($this->refresh($token)) {
                $refreshed++;
            } else {
                $failed++;
            }
        }

        return compact('refreshed', 'failed');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=TokenRefresher`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TokenRefresher.php tests/Feature/Services/TokenRefresherTest.php
git commit -m "feat: add TokenRefresher service with OAuth2 refresh and auto-deactivation"
```

---

## Chunk 3: Proxy Endpoint

### Task 8: AuthenticateToken middleware

**Files:**
- Create: `app/Http/Middleware/AuthenticateToken.php`
- Test: `tests/Feature/Middleware/AuthenticateTokenTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Middleware/AuthenticateTokenTest.php`:

```php
<?php

use App\Models\AccessToken;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Register a test route with the middleware
    Route::post('/test-auth', fn () => response()->json(['ok' => true]))
        ->middleware(\App\Http\Middleware\AuthenticateToken::class);
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AuthenticateToken`
Expected: FAIL

- [ ] **Step 3: Implement AuthenticateToken middleware**

Run: `php artisan make:middleware AuthenticateToken --no-interaction`

```php
<?php

namespace App\Http\Middleware;

use App\Models\AccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenValue = $request->header('x-api-key');

        if (! $tokenValue) {
            return $this->unauthorized();
        }

        $token = AccessToken::where('token', $tokenValue)
            ->where('is_active', true)
            ->first();

        if (! $token || $token->isExpired()) {
            return $this->unauthorized();
        }

        $token->updateQuietly(['last_used_at' => now()]);

        $request->attributes->set('access_token', $token);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'type' => 'error',
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Invalid API key',
            ],
        ], 401);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AuthenticateToken`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/AuthenticateToken.php tests/Feature/Middleware/AuthenticateTokenTest.php
git commit -m "feat: add AuthenticateToken middleware with Anthropic-compatible error format"
```

---

### Task 9: ProxyService and ProxyController

**Files:**
- Create: `app/Services/ProxyService.php`
- Create: `app/Http/Controllers/ProxyController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/ProxyTest.php`

- [ ] **Step 1: Write failing tests for the proxy endpoint**

Create `tests/Feature/ProxyTest.php`:

```php
<?php

use App\Models\AccessToken;
use App\Models\AnthropicApiKey;
use App\Models\RequestLog;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->token = AccessToken::create([
        'name' => 'Test Client',
        'token' => 'test-token',
        'refresh_token' => 'test-refresh',
        'is_active' => true,
        'token_expires_at' => now()->addDay(),
    ]);

    $this->apiKey = AnthropicApiKey::create([
        'name' => 'Key 1',
        'api_key' => 'sk-ant-api03-test',
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
    ], ['x-api-key' => 'test-token']);

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
    ], ['x-api-key' => 'test-token']);

    expect(RequestLog::count())->toBe(1);

    $log = RequestLog::first();
    expect($log->model)->toBe('claude-sonnet-4-6')
        ->and($log->input_tokens)->toBe(10)
        ->and($log->output_tokens)->toBe(5)
        ->and($log->status_code)->toBe(200)
        ->and($log->is_stream)->toBeFalse()
        ->and($log->access_token_id)->toBe($this->token->id)
        ->and($log->api_key_id)->toBe($this->apiKey->id);
});

it('forwards the raw body without modification', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['id' => 'msg_123', 'type' => 'message', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]]),
    ]);

    $rawBody = '{"model":"claude-sonnet-4-6","max_tokens":100,"messages":[{"role":"user","content":"Hi"}]}';

    $this->call('POST', '/v1/messages', [], [], [], [
        'HTTP_X_API_KEY' => 'test-token',
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
    ], ['x-api-key' => 'test-token']);

    $response->assertStatus(400)
        ->assertJsonPath('error.type', 'invalid_request_error');
});

it('retries with next API key on 429', function () {
    $key2 = AnthropicApiKey::create([
        'name' => 'Key 2',
        'api_key' => 'sk-ant-api03-test-2',
        'usage_order' => 2,
    ]);

    Http::fake(Http::sequence()
        ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error']], 429)
        ->push([
            'id' => 'msg_123',
            'type' => 'message',
            'content' => [['type' => 'text', 'text' => 'Success']],
            'model' => 'claude-sonnet-4-6',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)
    );

    $response = $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], ['x-api-key' => 'test-token']);

    $response->assertStatus(200)
        ->assertJsonPath('content.0.text', 'Success');
});

it('returns 401 without valid token', function () {
    $response = $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ]);

    $response->assertStatus(401);
});

it('returns error when no API keys available', function () {
    AnthropicApiKey::query()->delete();

    $response = $this->postJson('/v1/messages', [
        'model' => 'claude-sonnet-4-6',
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ], ['x-api-key' => 'test-token']);

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
        'x-api-key' => 'test-token',
        'anthropic-version' => '2024-01-01',
        'anthropic-beta' => 'some-beta-feature',
    ]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('anthropic-version', '2024-01-01')
            && $request->hasHeader('anthropic-beta', 'some-beta-feature')
            && $request->hasHeader('x-api-key');
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ProxyTest`
Expected: FAIL

- [ ] **Step 3: Implement ProxyService**

Create `app/Services/ProxyService.php`:

```php
<?php

namespace App\Services;

use App\Models\AccessToken;
use App\Models\RequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProxyService
{
    public function __construct(
        private ApiKeyRotator $apiKeyRotator,
        private CostCalculator $costCalculator,
        private StreamHandler $streamHandler,
    ) {}

    public function handle(Request $request, AccessToken $accessToken): Response
    {
        $rawBody = $request->getContent();
        $parsed = json_decode($rawBody, true) ?: [];
        $model = $parsed['model'] ?? 'unknown';
        $isStream = $parsed['stream'] ?? false;

        $requestedAt = now();
        $excludeKeyIds = [];
        $lastError = null;

        while (true) {
            $apiKey = $this->apiKeyRotator->selectNext($excludeKeyIds);

            if (! $apiKey) {
                if ($lastError) {
                    return $lastError;
                }

                return response()->json([
                    'type' => 'error',
                    'error' => [
                        'type' => 'api_error',
                        'message' => 'No API keys available',
                    ],
                ], 503);
            }

            $proxyHeaders = $this->buildProxyHeaders($request, $apiKey->api_key);

            if ($isStream) {
                $result = $this->handleStreamingRequest($rawBody, $proxyHeaders, $accessToken, $apiKey, $model, $requestedAt);
            } else {
                $result = $this->handleNonStreamingRequest($rawBody, $proxyHeaders, $accessToken, $apiKey, $model, $requestedAt);
            }

            // Retry on 429/529 with next key
            if ($result instanceof Response && in_array($this->getStatusFromResponse($result), [429, 529])) {
                $excludeKeyIds[] = $apiKey->id;
                $lastError = $result;

                continue;
            }

            return $result;
        }
    }

    private function handleNonStreamingRequest(
        string $rawBody,
        array $proxyHeaders,
        AccessToken $accessToken,
        \App\Models\AnthropicApiKey $apiKey,
        string $model,
        \Carbon\Carbon $requestedAt,
    ): Response {
        $startTime = microtime(true);

        try {
            $upstream = Http::withHeaders($proxyHeaders)
                ->withBody($rawBody, 'application/json')
                ->withOptions(['connect_timeout' => 10, 'timeout' => 300])
                ->post(config('airoxy.anthropic_api_url'));

            $responseBody = $upstream->body();
            $statusCode = $upstream->status();
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $responseData = json_decode($responseBody, true) ?: [];
            $usage = $responseData['usage'] ?? [];

            $this->logRequest(
                accessToken: $accessToken,
                apiKey: $apiKey,
                model: $model,
                isStream: false,
                statusCode: $statusCode,
                durationMs: $durationMs,
                requestedAt: $requestedAt,
                inputTokens: $usage['input_tokens'] ?? null,
                outputTokens: $usage['output_tokens'] ?? null,
                cacheCreationInputTokens: $usage['cache_creation_input_tokens'] ?? null,
                cacheReadInputTokens: $usage['cache_read_input_tokens'] ?? null,
                errorMessage: $statusCode >= 400 ? ($responseData['error']['message'] ?? null) : null,
            );

            return response($responseBody, $statusCode)
                ->withHeaders($this->forwardResponseHeaders($upstream));
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logRequest(
                accessToken: $accessToken,
                apiKey: $apiKey,
                model: $model,
                isStream: false,
                statusCode: 502,
                durationMs: $durationMs,
                requestedAt: $requestedAt,
                errorMessage: $e->getMessage(),
            );

            return response()->json([
                'type' => 'error',
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Upstream connection failed',
                ],
            ], 502);
        }
    }

    private function handleStreamingRequest(
        string $rawBody,
        array $proxyHeaders,
        AccessToken $accessToken,
        \App\Models\AnthropicApiKey $apiKey,
        string $model,
        \Carbon\Carbon $requestedAt,
    ): Response {
        $startTime = microtime(true);

        try {
            $upstream = Http::withHeaders($proxyHeaders)
                ->withBody($rawBody, 'application/json')
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => 300,
                    'connect_timeout' => 10,
                ])
                ->post(config('airoxy.anthropic_api_url'));

            $statusCode = $upstream->status();

            // Non-200 before stream starts: forward error as regular response
            if ($statusCode !== 200) {
                $responseBody = $upstream->body();
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);
                $responseData = json_decode($responseBody, true) ?: [];

                $this->logRequest(
                    accessToken: $accessToken,
                    apiKey: $apiKey,
                    model: $model,
                    isStream: true,
                    statusCode: $statusCode,
                    durationMs: $durationMs,
                    requestedAt: $requestedAt,
                    errorMessage: $responseData['error']['message'] ?? null,
                );

                return response($responseBody, $statusCode)
                    ->withHeaders($this->forwardResponseHeaders($upstream));
            }

            // Stream success response
            $streamHandler = $this->streamHandler;
            $streamHandler->reset();
            $proxyService = $this;

            return new StreamedResponse(function () use ($upstream, $streamHandler, $proxyService, $accessToken, $apiKey, $model, $requestedAt, $startTime) {
                try {
                    $body = $upstream->toPsrResponse()->getBody();

                    while (! $body->eof()) {
                        if (connection_aborted()) {
                            break;
                        }

                        $chunk = $body->read(8192);

                        if ($chunk !== '') {
                            echo $chunk;
                            flush();
                            $streamHandler->parseSSEChunk($chunk);
                        }
                    }
                } catch (\Throwable $e) {
                    $errorMessage = $e->getMessage();
                } finally {
                    $durationMs = (int) ((microtime(true) - $startTime) * 1000);

                    $proxyService->logRequest(
                        accessToken: $accessToken,
                        apiKey: $apiKey,
                        model: $model,
                        isStream: true,
                        statusCode: 200,
                        durationMs: $durationMs,
                        requestedAt: $requestedAt,
                        inputTokens: $streamHandler->getInputTokens(),
                        outputTokens: $streamHandler->getOutputTokens(),
                        cacheCreationInputTokens: $streamHandler->getCacheCreationInputTokens(),
                        cacheReadInputTokens: $streamHandler->getCacheReadInputTokens(),
                        errorMessage: $errorMessage ?? null,
                    );
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logRequest(
                accessToken: $accessToken,
                apiKey: $apiKey,
                model: $model,
                isStream: true,
                statusCode: 502,
                durationMs: $durationMs,
                requestedAt: $requestedAt,
                errorMessage: $e->getMessage(),
            );

            return response()->json([
                'type' => 'error',
                'error' => ['type' => 'api_error', 'message' => 'Upstream connection failed'],
            ], 502);
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildProxyHeaders(Request $request, string $anthropicApiKey): array
    {
        $headers = [
            'x-api-key' => $anthropicApiKey,
            'content-type' => 'application/json',
        ];

        // Forward anthropic-specific headers from client
        $anthropicVersion = $request->header('anthropic-version');
        $headers['anthropic-version'] = $anthropicVersion ?: config('airoxy.anthropic_version');

        if ($beta = $request->header('anthropic-beta')) {
            $headers['anthropic-beta'] = $beta;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function forwardResponseHeaders(\Illuminate\Http\Client\Response $response): array
    {
        $forwardHeaders = [];
        $allowedPrefixes = ['x-ratelimit-', 'retry-after', 'request-id'];

        foreach ($response->headers() as $name => $values) {
            $lower = strtolower($name);
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    $forwardHeaders[$name] = $values[0] ?? '';
                    break;
                }
            }
        }

        // Forward Anthropic's content-type as-is
        if ($contentType = $response->header('content-type')) {
            $forwardHeaders['content-type'] = $contentType;
        }

        return $forwardHeaders;
    }

    public function logRequest(
        AccessToken $accessToken,
        \App\Models\AnthropicApiKey $apiKey,
        string $model,
        bool $isStream,
        int $statusCode,
        int $durationMs,
        \Carbon\Carbon $requestedAt,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $cacheCreationInputTokens = null,
        ?int $cacheReadInputTokens = null,
        ?string $errorMessage = null,
    ): void {
        $estimatedCost = $this->costCalculator->calculate(
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreationInputTokens: $cacheCreationInputTokens,
            cacheReadInputTokens: $cacheReadInputTokens,
        );

        RequestLog::create([
            'access_token_id' => $accessToken->id,
            'api_key_id' => $apiKey->id,
            'model' => $model,
            'endpoint' => '/v1/messages',
            'is_stream' => $isStream,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_creation_input_tokens' => $cacheCreationInputTokens,
            'cache_read_input_tokens' => $cacheReadInputTokens,
            'estimated_cost_usd' => $estimatedCost,
            'status_code' => $statusCode,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'requested_at' => $requestedAt,
            'created_at' => now(),
        ]);
    }

    private function getStatusFromResponse(Response $response): int
    {
        return $response->getStatusCode();
    }
}
```

- [ ] **Step 4: Implement ProxyController**

Create `app/Http/Controllers/ProxyController.php` (overwrite the empty one):

```php
<?php

namespace App\Http\Controllers;

use App\Services\ProxyService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProxyController extends Controller
{
    public function handle(Request $request, ProxyService $proxyService): Response
    {
        $accessToken = $request->attributes->get('access_token');

        return $proxyService->handle($request, $accessToken);
    }
}
```

- [ ] **Step 5: Update routes/api.php**

```php
<?php

use App\Http\Controllers\ProxyController;
use App\Http\Middleware\AuthenticateToken;
use Illuminate\Support\Facades\Route;

Route::post('/v1/messages', [ProxyController::class, 'handle'])
    ->middleware(AuthenticateToken::class);
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ProxyTest`
Expected: All pass.

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Services/ProxyService.php app/Http/Controllers/ProxyController.php routes/api.php tests/Feature/ProxyTest.php
git commit -m "feat: add proxy endpoint with streaming SSE support, key retry, and logging"
```

---

## Chunk 4: CLI Commands

### Task 10: API Key CLI commands

**Files:**
- Create: `app/Console/Commands/ApiKeyAddCommand.php`
- Create: `app/Console/Commands/ApiKeyListCommand.php`
- Create: `app/Console/Commands/ApiKeyRemoveCommand.php`
- Create: `app/Console/Commands/ApiKeyToggleCommand.php`
- Test: `tests/Feature/Commands/ApiKeyCommandsTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Commands/ApiKeyCommandsTest.php`:

```php
<?php

use App\Models\AnthropicApiKey;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ApiKeyCommands`
Expected: FAIL

- [ ] **Step 3: Implement all 4 API key commands**

Create `app/Console/Commands/ApiKeyAddCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AnthropicApiKey;
use Illuminate\Console\Command;

class ApiKeyAddCommand extends Command
{
    protected $signature = 'airoxy:api-key:add {api_key} {--name=}';

    protected $description = 'Add a new Anthropic API key';

    public function handle(): int
    {
        $maxOrder = AnthropicApiKey::max('usage_order') ?? 0;

        $key = AnthropicApiKey::create([
            'name' => $this->option('name'),
            'api_key' => $this->argument('api_key'),
            'usage_order' => $maxOrder + 1,
        ]);

        $this->info("API key added successfully (ID: {$key->id}).");

        return self::SUCCESS;
    }
}
```

Create `app/Console/Commands/ApiKeyListCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AnthropicApiKey;
use Illuminate\Console\Command;

class ApiKeyListCommand extends Command
{
    protected $signature = 'airoxy:api-key:list';

    protected $description = 'List all Anthropic API keys';

    public function handle(): int
    {
        $keys = AnthropicApiKey::orderBy('usage_order')->get();

        if ($keys->isEmpty()) {
            $this->warn('No API keys found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Key', 'Active', 'Order', 'Last Used'],
            $keys->map(fn (AnthropicApiKey $key) => [
                $key->id,
                $key->name ?? '-',
                $key->masked_key,
                $key->is_active ? 'Yes' : 'No',
                $key->usage_order,
                $key->last_used_at?->diffForHumans() ?? 'Never',
            ]),
        );

        return self::SUCCESS;
    }
}
```

Create `app/Console/Commands/ApiKeyRemoveCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AnthropicApiKey;
use Illuminate\Console\Command;

class ApiKeyRemoveCommand extends Command
{
    protected $signature = 'airoxy:api-key:remove {id}';

    protected $description = 'Remove an Anthropic API key';

    public function handle(): int
    {
        $key = AnthropicApiKey::find($this->argument('id'));

        if (! $key) {
            $this->error('API key not found.');

            return self::FAILURE;
        }

        $key->delete();
        $this->info("API key '{$key->name}' (ID: {$key->id}) removed.");

        return self::SUCCESS;
    }
}
```

Create `app/Console/Commands/ApiKeyToggleCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AnthropicApiKey;
use Illuminate\Console\Command;

class ApiKeyToggleCommand extends Command
{
    protected $signature = 'airoxy:api-key:toggle {id}';

    protected $description = 'Toggle an API key active/inactive';

    public function handle(): int
    {
        $key = AnthropicApiKey::find($this->argument('id'));

        if (! $key) {
            $this->error('API key not found.');

            return self::FAILURE;
        }

        $key->update(['is_active' => ! $key->is_active]);
        $status = $key->is_active ? 'activated' : 'deactivated';
        $this->info("API key '{$key->name}' (ID: {$key->id}) {$status}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ApiKeyCommands`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ApiKey*.php tests/Feature/Commands/ApiKeyCommandsTest.php
git commit -m "feat: add API key CLI commands (add, list, remove, toggle)"
```

---

### Task 11: Token CLI commands

**Files:**
- Create: `app/Console/Commands/TokenAddCommand.php`
- Create: `app/Console/Commands/TokenListCommand.php`
- Create: `app/Console/Commands/TokenRemoveCommand.php`
- Create: `app/Console/Commands/TokenRefreshCommand.php`
- Create: `app/Console/Commands/TokenAutoCommand.php`
- Test: `tests/Feature/Commands/TokenCommandsTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Commands/TokenCommandsTest.php`:

```php
<?php

use App\Models\AccessToken;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

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
    Http::fake([
        'platform.claude.com/*' => Http::response([
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 28800,
        ]),
    ]);

    AccessToken::create(['name' => 'T1', 'token' => 'old-1', 'refresh_token' => 'r1']);
    AccessToken::create(['name' => 'T2', 'token' => 'old-2', 'refresh_token' => 'r2']);

    $this->artisan('airoxy:token:refresh')
        ->assertExitCode(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=TokenCommands`
Expected: FAIL

- [ ] **Step 3: Implement token commands**

Create `app/Console/Commands/TokenAddCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Illuminate\Console\Command;

class TokenAddCommand extends Command
{
    protected $signature = 'airoxy:token:add {token} {refresh_token} {--name=} {--expires-in=28800}';

    protected $description = 'Add a new access token';

    public function handle(): int
    {
        $token = AccessToken::create([
            'name' => $this->option('name'),
            'token' => $this->argument('token'),
            'refresh_token' => $this->argument('refresh_token'),
            'token_expires_at' => now()->addSeconds((int) $this->option('expires-in')),
        ]);

        $this->info("Access token added successfully (ID: {$token->id}).");

        return self::SUCCESS;
    }
}
```

Create `app/Console/Commands/TokenListCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Illuminate\Console\Command;

class TokenListCommand extends Command
{
    protected $signature = 'airoxy:token:list';

    protected $description = 'List all access tokens';

    public function handle(): int
    {
        $tokens = AccessToken::orderBy('id')->get();

        if ($tokens->isEmpty()) {
            $this->warn('No access tokens found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Token', 'Active', 'Expires', 'Fails', 'Last Used'],
            $tokens->map(fn (AccessToken $token) => [
                $token->id,
                $token->name ?? '-',
                $token->masked_token,
                $token->is_active ? 'Yes' : 'No',
                $token->token_expires_at?->diffForHumans() ?? '-',
                $token->refresh_fail_count,
                $token->last_used_at?->diffForHumans() ?? 'Never',
            ]),
        );

        return self::SUCCESS;
    }
}
```

Create `app/Console/Commands/TokenRemoveCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Illuminate\Console\Command;

class TokenRemoveCommand extends Command
{
    protected $signature = 'airoxy:token:remove {id}';

    protected $description = 'Remove an access token';

    public function handle(): int
    {
        $token = AccessToken::find($this->argument('id'));

        if (! $token) {
            $this->error('Token not found.');

            return self::FAILURE;
        }

        $token->delete();
        $this->info("Token '{$token->name}' (ID: {$token->id}) removed.");

        return self::SUCCESS;
    }
}
```

Create `app/Console/Commands/TokenRefreshCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use App\Services\TokenRefresher;
use Illuminate\Console\Command;

class TokenRefreshCommand extends Command
{
    protected $signature = 'airoxy:token:refresh {--id=}';

    protected $description = 'Refresh access tokens via OAuth2';

    public function handle(TokenRefresher $refresher): int
    {
        if ($id = $this->option('id')) {
            $token = AccessToken::find($id);

            if (! $token) {
                $this->error('Token not found.');

                return self::FAILURE;
            }

            $result = $refresher->refresh($token);
            $this->info($result ? 'Token refreshed successfully.' : 'Token refresh failed.');

            return $result ? self::SUCCESS : self::FAILURE;
        }

        $result = $refresher->refreshAll();
        $this->info("Refreshed: {$result['refreshed']}, Failed: {$result['failed']}");

        return self::SUCCESS;
    }
}
```

Create `app/Console/Commands/TokenAutoCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TokenAutoCommand extends Command
{
    protected $signature = 'airoxy:token:auto {--dry-run} {--path=}';

    protected $description = 'Auto-import tokens from Claude Code credentials files';

    public function handle(): int
    {
        $paths = $this->getCredentialPaths();
        $found = 0;
        $added = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($paths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $content = json_decode(file_get_contents($path), true);
            $oauth = $content['claudeAiOauth'] ?? null;

            if (! $oauth || ! isset($oauth['accessToken'], $oauth['refreshToken'])) {
                continue;
            }

            $found++;
            $username = $this->extractUsername($path);

            $existing = AccessToken::where('refresh_token', $oauth['refreshToken'])->first();

            if ($existing) {
                if (! $this->option('dry-run')) {
                    $existing->update([
                        'token' => $oauth['accessToken'],
                        'token_expires_at' => isset($oauth['expiresAt'])
                            ? Carbon::createFromTimestampMs($oauth['expiresAt'])
                            : null,
                    ]);
                    $updated++;
                } else {
                    $this->line("  [UPDATE] {$username}: {$path}");
                    $skipped++;
                }

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  [NEW] {$username}: {$path}");
                $skipped++;

                continue;
            }

            AccessToken::create([
                'name' => $username,
                'token' => $oauth['accessToken'],
                'refresh_token' => $oauth['refreshToken'],
                'token_expires_at' => isset($oauth['expiresAt'])
                    ? Carbon::createFromTimestampMs($oauth['expiresAt'])
                    : null,
            ]);

            $added++;
        }

        $this->info("Scanned paths: " . count($paths));
        $this->info("Found credentials: {$found}");
        $this->info("New: {$added}");
        $this->info("Updated: {$updated}");

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes made.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string>
     */
    private function getCredentialPaths(): array
    {
        if ($customPath = $this->option('path')) {
            return [$customPath];
        }

        $paths = ['/root/.claude/.credentials.json'];

        if (is_dir('/home')) {
            foreach (glob('/home/*') as $homeDir) {
                $credPath = $homeDir . '/.claude/.credentials.json';
                $paths[] = $credPath;
            }
        }

        return $paths;
    }

    private function extractUsername(string $path): string
    {
        if (str_starts_with($path, '/root/')) {
            return 'root';
        }

        if (preg_match('#/home/([^/]+)/#', $path, $matches)) {
            return $matches[1];
        }

        return basename(dirname(dirname($path)));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=TokenCommands`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/Token*.php tests/Feature/Commands/TokenCommandsTest.php
git commit -m "feat: add token CLI commands (add, list, remove, refresh, auto)"
```

---

### Task 12: Logs and Stats CLI commands

**Files:**
- Create: `app/Console/Commands/LogsCommand.php`
- Create: `app/Console/Commands/StatsCommand.php`
- Test: `tests/Feature/Commands/LogsStatsCommandsTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Commands/LogsStatsCommandsTest.php`:

```php
<?php

use App\Models\AccessToken;
use App\Models\AnthropicApiKey;
use App\Models\RequestLog;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=LogsStats`
Expected: FAIL

- [ ] **Step 3: Implement LogsCommand**

Create `app/Console/Commands/LogsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\RequestLog;
use Illuminate\Console\Command;

class LogsCommand extends Command
{
    protected $signature = 'airoxy:logs {--limit=50} {--token=} {--today} {--date=}';

    protected $description = 'Show request logs';

    public function handle(): int
    {
        $query = RequestLog::with(['accessToken', 'apiKey'])
            ->orderByDesc('requested_at');

        if ($this->option('token')) {
            $query->where('access_token_id', $this->option('token'));
        }

        if ($this->option('today')) {
            $query->whereDate('requested_at', today());
        } elseif ($date = $this->option('date')) {
            $query->whereDate('requested_at', $date);
        }

        $logs = $query->limit((int) $this->option('limit'))->get();

        if ($logs->isEmpty()) {
            $this->warn('No logs found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Token', 'Model', 'Stream', 'In/Out', 'Cache(W/R)', 'Cost', 'Status', 'Duration', 'Date'],
            $logs->map(fn (RequestLog $log) => [
                $log->id,
                $log->accessToken?->name ?? '-',
                $log->model,
                $log->is_stream ? 'Yes' : 'No',
                number_format($log->input_tokens ?? 0) . '/' . number_format($log->output_tokens ?? 0),
                number_format($log->cache_creation_input_tokens ?? 0) . '/' . number_format($log->cache_read_input_tokens ?? 0),
                '$' . number_format($log->estimated_cost_usd ?? 0, 6),
                $log->status_code,
                $log->duration_ms . 'ms',
                $log->requested_at?->format('Y-m-d H:i:s'),
            ]),
        );

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Implement StatsCommand**

Create `app/Console/Commands/StatsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use App\Models\AnthropicApiKey;
use App\Models\DailyStat;
use App\Models\RequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StatsCommand extends Command
{
    protected $signature = 'airoxy:stats {--today} {--month} {--token=}';

    protected $description = 'Show usage statistics';

    public function handle(): int
    {
        // Recent stats from request_logs (only non-aggregated days to avoid double-counting)
        $aggregatedDates = DailyStat::pluck('date')->map(fn ($d) => $d->format('Y-m-d'))->toArray();
        $recentQuery = RequestLog::query();
        if ($aggregatedDates) {
            $recentQuery->whereNotIn(DB::raw('DATE(requested_at)'), $aggregatedDates);
        }
        // Historical stats from daily_stats
        $historicalQuery = DailyStat::whereNull('access_token_id')->whereNull('model');

        if ($this->option('token')) {
            $recentQuery->where('access_token_id', $this->option('token'));
            $historicalQuery = DailyStat::where('access_token_id', $this->option('token'))->whereNull('model');
        }

        if ($this->option('today')) {
            $recentQuery->whereDate('requested_at', today());
            $historicalQuery->whereDate('date', today());
        } elseif ($this->option('month')) {
            $recentQuery->whereMonth('requested_at', now()->month)
                ->whereYear('requested_at', now()->year);
            $historicalQuery->whereMonth('date', now()->month)
                ->whereYear('date', now()->year);
        }

        // Combine recent and historical
        $recentStats = $recentQuery->selectRaw('
            COUNT(*) as total_requests,
            SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests,
            SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests,
            COALESCE(SUM(input_tokens), 0) as total_input_tokens,
            COALESCE(SUM(output_tokens), 0) as total_output_tokens,
            COALESCE(SUM(cache_creation_input_tokens), 0) as total_cache_creation_tokens,
            COALESCE(SUM(cache_read_input_tokens), 0) as total_cache_read_tokens,
            COALESCE(SUM(estimated_cost_usd), 0) as total_cost
        ')->first();

        $historicalStats = $historicalQuery->selectRaw('
            COALESCE(SUM(total_requests), 0) as total_requests,
            COALESCE(SUM(successful_requests), 0) as successful_requests,
            COALESCE(SUM(failed_requests), 0) as failed_requests,
            COALESCE(SUM(total_input_tokens), 0) as total_input_tokens,
            COALESCE(SUM(total_output_tokens), 0) as total_output_tokens,
            COALESCE(SUM(total_cache_creation_tokens), 0) as total_cache_creation_tokens,
            COALESCE(SUM(total_cache_read_tokens), 0) as total_cache_read_tokens,
            COALESCE(SUM(total_estimated_cost_usd), 0) as total_cost
        ')->first();

        $totalRequests = $recentStats->total_requests + $historicalStats->total_requests;
        $successRequests = $recentStats->successful_requests + $historicalStats->successful_requests;
        $failedRequests = $recentStats->failed_requests + $historicalStats->failed_requests;
        $totalInput = $recentStats->total_input_tokens + $historicalStats->total_input_tokens;
        $totalOutput = $recentStats->total_output_tokens + $historicalStats->total_output_tokens;
        $totalCacheWrite = $recentStats->total_cache_creation_tokens + $historicalStats->total_cache_creation_tokens;
        $totalCacheRead = $recentStats->total_cache_read_tokens + $historicalStats->total_cache_read_tokens;
        $totalCost = $recentStats->total_cost + $historicalStats->total_cost;

        $this->line('');
        $this->line(str_repeat('=', 50));
        $this->line('  AIROXY STATISTICS');
        $this->line(str_repeat('=', 50));
        $this->line("  Total Requests           : " . number_format($totalRequests));
        $this->line("  Successful Requests      : " . number_format($successRequests));
        $this->line("  Failed Requests          : " . number_format($failedRequests));
        $this->line("  Total Input Tokens       : " . number_format($totalInput));
        $this->line("  Total Output Tokens      : " . number_format($totalOutput));
        $this->line("  Cache Write Tokens       : " . number_format($totalCacheWrite));
        $this->line("  Cache Read Tokens        : " . number_format($totalCacheRead));
        $this->line("  Estimated Total Cost     : \$" . number_format($totalCost, 2));
        $this->line(str_repeat('-', 50));
        $this->line("  Active API Keys          : " . AnthropicApiKey::where('is_active', true)->count());
        $this->line("  Active Tokens            : " . AccessToken::where('is_active', true)->count());
        $this->line(str_repeat('=', 50));
        $this->line('');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=LogsStats`
Expected: All pass.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/LogsCommand.php app/Console/Commands/StatsCommand.php tests/Feature/Commands/LogsStatsCommandsTest.php
git commit -m "feat: add logs and stats CLI commands"
```

---

### Task 13: ServeCommand

**Files:**
- Create: `app/Console/Commands/ServeCommand.php`

- [ ] **Step 1: Implement ServeCommand**

Create `app/Console/Commands/ServeCommand.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ServeCommand extends Command
{
    protected $signature = 'airoxy:serve';

    protected $description = 'Start the Airoxy proxy server';

    public function handle(): int
    {
        $host = config('airoxy.host');
        $port = config('airoxy.port');

        $this->info("Starting Airoxy on {$host}:{$port}...");

        return $this->call('octane:start', [
            '--server' => 'frankenphp',
            '--host' => $host,
            '--port' => $port,
        ]);
    }
}
```

- [ ] **Step 2: Verify command is registered**

Run: `php artisan list airoxy`
Expected: `airoxy:serve` appears in the command list.

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/ServeCommand.php
git commit -m "feat: add airoxy:serve command wrapping Octane"
```

---

## Chunk 5: Scheduler, Deployment, Cleanup

### Task 14: Scheduler — Token refresh + Log aggregation/purge

**Files:**
- Modify: `routes/console.php`
- Test: `tests/Feature/SchedulerTest.php`

- [ ] **Step 1: Write failing tests for scheduler jobs**

Create `tests/Feature/SchedulerTest.php`:

```php
<?php

use App\Models\AccessToken;
use App\Models\AnthropicApiKey;
use App\Models\DailyStat;
use App\Models\RequestLog;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('aggregates request logs into daily stats', function () {
    $token = AccessToken::create(['name' => 'T1', 'token' => 't1', 'refresh_token' => 'r1']);
    $key = AnthropicApiKey::create(['name' => 'K1', 'api_key' => 'k1', 'usage_order' => 1]);

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

    // Per-token-per-model stat
    expect(DailyStat::where('access_token_id', $token->id)->where('model', 'claude-sonnet-4-6')->count())->toBe(1);

    $stat = DailyStat::where('access_token_id', $token->id)->where('model', 'claude-sonnet-4-6')->first();
    expect($stat->total_requests)->toBe(1)
        ->and($stat->total_input_tokens)->toBe(100)
        ->and($stat->total_output_tokens)->toBe(50);
});

it('purges old request logs', function () {
    $token = AccessToken::create(['name' => 'T1', 'token' => 't1', 'refresh_token' => 'r1']);
    $key = AnthropicApiKey::create(['name' => 'K1', 'api_key' => 'k1', 'usage_order' => 1]);

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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=Scheduler`
Expected: FAIL

- [ ] **Step 3: Create AggregateStatsCommand**

Create `app/Console/Commands/AggregateStatsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\DailyStat;
use App\Models\RequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateStatsCommand extends Command
{
    protected $signature = 'airoxy:aggregate-stats';

    protected $description = 'Aggregate request logs into daily stats';

    public function handle(): int
    {
        $retentionDays = config('airoxy.log_retention_days', 3);

        // Only aggregate completed days that haven't been aggregated yet
        $days = RequestLog::select(DB::raw('DATE(requested_at) as log_date'))
            ->where('requested_at', '<', today())
            ->groupBy('log_date')
            ->pluck('log_date');

        $aggregated = 0;

        foreach ($days as $date) {
            // Skip if already aggregated
            if (DailyStat::where('date', $date)->exists()) {
                continue;
            }

            // Per token+model stats
            $stats = RequestLog::select([
                DB::raw('DATE(requested_at) as date'),
                'access_token_id',
                'model',
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests'),
                DB::raw('SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests'),
                DB::raw('COALESCE(SUM(input_tokens), 0) as total_input_tokens'),
                DB::raw('COALESCE(SUM(output_tokens), 0) as total_output_tokens'),
                DB::raw('COALESCE(SUM(cache_creation_input_tokens), 0) as total_cache_creation_tokens'),
                DB::raw('COALESCE(SUM(cache_read_input_tokens), 0) as total_cache_read_tokens'),
                DB::raw('COALESCE(SUM(estimated_cost_usd), 0) as total_estimated_cost_usd'),
            ])
            ->whereDate('requested_at', $date)
            ->groupBy('date', 'access_token_id', 'model')
            ->get();

            foreach ($stats as $stat) {
                DailyStat::create($stat->toArray());
            }

            // Rollup row (all tokens, all models)
            $rollup = RequestLog::select([
                DB::raw("'{$date}' as date"),
                DB::raw('NULL as access_token_id'),
                DB::raw('NULL as model'),
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests'),
                DB::raw('SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests'),
                DB::raw('COALESCE(SUM(input_tokens), 0) as total_input_tokens'),
                DB::raw('COALESCE(SUM(output_tokens), 0) as total_output_tokens'),
                DB::raw('COALESCE(SUM(cache_creation_input_tokens), 0) as total_cache_creation_tokens'),
                DB::raw('COALESCE(SUM(cache_read_input_tokens), 0) as total_cache_read_tokens'),
                DB::raw('COALESCE(SUM(estimated_cost_usd), 0) as total_estimated_cost_usd'),
            ])
            ->whereDate('requested_at', $date)
            ->first();

            DailyStat::create($rollup->toArray());

            $aggregated++;
        }

        $this->info("Aggregated {$aggregated} day(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Create PurgeLogsCommand**

Create `app/Console/Commands/PurgeLogsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\RequestLog;
use Illuminate\Console\Command;

class PurgeLogsCommand extends Command
{
    protected $signature = 'airoxy:purge-logs';

    protected $description = 'Purge request logs older than retention period';

    public function handle(): int
    {
        $days = config('airoxy.log_retention_days', 3);
        $cutoff = now()->subDays($days);

        $deleted = RequestLog::where('requested_at', '<', $cutoff)->delete();

        $this->info("Purged {$deleted} log(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Register scheduler in routes/console.php**

```php
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('airoxy:token:refresh')
    ->everyNMinutes((int) config('airoxy.token_refresh_interval', 360));

Schedule::command('airoxy:aggregate-stats')->dailyAt('01:00');
Schedule::command('airoxy:purge-logs')->dailyAt('01:30');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=Scheduler`
Expected: All pass.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/AggregateStatsCommand.php app/Console/Commands/PurgeLogsCommand.php routes/console.php tests/Feature/SchedulerTest.php
git commit -m "feat: add scheduler for token refresh, log aggregation, and log purge"
```

---

### Task 15: Deployment files — bin/airoxy, supervisor config, install.sh

**Files:**
- Create: `bin/airoxy`
- Create: `supervisor/airoxy-worker.conf`
- Create: `install.sh`

- [ ] **Step 1: Create bin/airoxy**

```bash
#!/bin/bash
AIROXY_PATH="/var/www/airoxy"
cd "$AIROXY_PATH" && php artisan "airoxy:$1" "${@:2}"
```

Run: `chmod +x bin/airoxy`

- [ ] **Step 2: Create supervisor/airoxy-worker.conf**

```ini
[program:airoxy]
process_name=%(program_name)s
command=php /var/www/airoxy/artisan airoxy:serve
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/airoxy/airoxy.log
stopwaitsecs=30

[program:airoxy-scheduler]
process_name=%(program_name)s
command=/bin/bash -c "while true; do php /var/www/airoxy/artisan schedule:run --no-interaction >> /dev/null 2>&1; sleep 60; done"
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/airoxy/scheduler.log
stopwaitsecs=10
```

- [ ] **Step 3: Create install.sh**

```bash
#!/bin/bash
set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

INSTALL_PATH="/var/www/airoxy"

echo -e "${GREEN}=== Airoxy Installer ===${NC}"
echo ""

# 1. Preflight checks
echo -e "${YELLOW}[1/7] Preflight checks...${NC}"

# Check Ubuntu
if ! grep -qi ubuntu /etc/os-release 2>/dev/null; then
    echo -e "${RED}Error: Ubuntu is required.${NC}"
    exit 1
fi

# Check PHP version
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;' 2>/dev/null || echo "0")
if [ "$PHP_MAJOR" -lt 8 ] || { [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 5 ]; }; then
    echo -e "${RED}Error: PHP 8.5+ is required (found: ${PHP_MAJOR}.${PHP_MINOR}).${NC}"
    exit 1
fi
PHP_VERSION="${PHP_MAJOR}.${PHP_MINOR}"
echo "  PHP $PHP_VERSION ✓"

# Check PHP extensions
for ext in curl mbstring sqlite3 openssl tokenizer xml; do
    if ! php -m | grep -qi "^$ext$"; then
        echo -e "${RED}Error: PHP extension '$ext' is missing.${NC}"
        exit 1
    fi
done
echo "  PHP extensions ✓"

# Check/install composer
if ! command -v composer &>/dev/null; then
    echo "  Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
echo "  Composer ✓"

# Check/install supervisor
if ! command -v supervisorctl &>/dev/null; then
    echo "  Installing Supervisor..."
    apt-get update -qq && apt-get install -y -qq supervisor
fi
echo "  Supervisor ✓"

# Check git
if ! command -v git &>/dev/null; then
    echo -e "${RED}Error: git is required.${NC}"
    exit 1
fi
echo "  Git ✓"

# 2. Clone & setup
echo ""
echo -e "${YELLOW}[2/7] Cloning repository...${NC}"

if [ -d "$INSTALL_PATH" ]; then
    echo -e "${YELLOW}  $INSTALL_PATH already exists. Pulling latest...${NC}"
    cd "$INSTALL_PATH" && git pull
else
    git clone https://github.com/oralunal/airoxy.git "$INSTALL_PATH"
    cd "$INSTALL_PATH"
fi

echo ""
echo -e "${YELLOW}[3/7] Installing dependencies...${NC}"
composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --no-interaction
fi

touch database/database.sqlite
php artisan migrate --force --no-interaction

# 3. Install Octane/FrankenPHP
echo ""
echo -e "${YELLOW}[4/7] Setting up Octane + FrankenPHP...${NC}"
php artisan octane:install --server=frankenphp --no-interaction 2>/dev/null || true

# 4. Permissions
echo ""
echo -e "${YELLOW}[5/7] Setting permissions...${NC}"
chown -R www-data:www-data "$INSTALL_PATH"
chmod -R 775 storage bootstrap/cache database

# 5. Global alias
echo ""
echo -e "${YELLOW}[6/7] Installing global alias...${NC}"
cp "$INSTALL_PATH/bin/airoxy" /usr/local/bin/airoxy
chmod +x /usr/local/bin/airoxy
echo "  /usr/local/bin/airoxy ✓"

# 6. Supervisor
echo ""
echo -e "${YELLOW}[7/7] Configuring Supervisor...${NC}"
mkdir -p /var/log/airoxy
cp "$INSTALL_PATH/supervisor/airoxy-worker.conf" /etc/supervisor/conf.d/airoxy.conf
supervisorctl reread
supervisorctl update
echo "  Supervisor configured ✓"

# 7. Summary
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Airoxy installed successfully!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "  Next steps:"
echo "    1. Add API keys:    airoxy api-key:add YOUR_KEY --name='Key 1'"
echo "    2. Add tokens:      airoxy token:auto"
echo "    3. Start server:    supervisorctl start airoxy"
echo ""
echo "  Or start manually:    airoxy serve"
echo "  View logs:            airoxy logs"
echo "  View stats:           airoxy stats"
echo ""
```

Run: `chmod +x install.sh`

- [ ] **Step 4: Update .env.example with all Airoxy settings**

Ensure `.env.example` contains:

```env
APP_NAME=Airoxy

AIROXY_HOST=0.0.0.0
AIROXY_PORT=3800
AIROXY_TOKEN_REFRESH_INTERVAL=360
AIROXY_LOG_RETENTION_DAYS=3

DB_CONNECTION=sqlite
```

- [ ] **Step 5: Commit**

```bash
git add bin/airoxy supervisor/airoxy-worker.conf install.sh .env.example
git commit -m "feat: add deployment files (install.sh, supervisor config, global alias)"
```

---

### Task 16: Enable RefreshDatabase in Pest.php and run full test suite

**Files:**
- Modify: `tests/Pest.php`

- [ ] **Step 1: Enable RefreshDatabase in Pest.php**

Uncomment the `RefreshDatabase` line in `tests/Pest.php`:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

This allows removing the `uses(RefreshDatabase::class)` line from individual feature test files (but both work — this is optional cleanup).

- [ ] **Step 2: Run full test suite**

Run: `php artisan test --compact`
Expected: All tests pass.

- [ ] **Step 3: Run Pint on everything**

Run: `vendor/bin/pint --format agent`

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: enable RefreshDatabase globally, run Pint on all files"
```

---

### Task 17: Final cleanup and verification

- [ ] **Step 1: Remove unused User model and default migrations**

The project doesn't need the `User` model, user migration, cache table migration, or jobs table migration. Delete:
- `app/Models/User.php`
- `database/migrations/0001_01_01_000000_create_users_table.php`
- `database/migrations/0001_01_01_000001_create_cache_table.php`
- `database/migrations/0001_01_01_000002_create_jobs_table.php`

Also remove the default test files if present:
- `tests/Feature/ExampleTest.php`
- `tests/Unit/ExampleTest.php`

- [ ] **Step 2: Recreate fresh database**

Run: `rm database/database.sqlite && touch database/database.sqlite && php artisan migrate --no-interaction`
Expected: Only Airoxy tables created.

- [ ] **Step 3: Verify all commands are registered**

Run: `php artisan list airoxy`
Expected: All 12 commands listed:
```
airoxy:serve
airoxy:api-key:add
airoxy:api-key:list
airoxy:api-key:remove
airoxy:api-key:toggle
airoxy:token:add
airoxy:token:list
airoxy:token:remove
airoxy:token:refresh
airoxy:token:auto
airoxy:logs
airoxy:stats
airoxy:aggregate-stats
airoxy:purge-logs
```

- [ ] **Step 4: Run full test suite one final time**

Run: `php artisan test --compact`
Expected: All tests pass with 0 failures.

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "chore: remove unused scaffolding, verify clean project state"
```
