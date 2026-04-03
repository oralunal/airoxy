# Airoxy — Anthropic API Proxy Server Design Spec

## Overview

Airoxy is a Laravel-based transparent proxy server for Anthropic's Messages API. It authenticates incoming requests via access tokens (OAuth2 refresh-capable), routes them to Anthropic using round-robin API key rotation, and logs usage with cost estimation. There is no UI — all management is via CLI commands and the `airoxy` global alias.

**Repo:** `github.com/oralunal/airoxy`

## Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Server | Laravel Octane + FrankenPHP | High concurrency, streaming SSE support, standalone process |
| Database | SQLite | Simple, no external dependencies, sufficient for proxy workload |
| PHP Version | 8.5 minimum | Required by project |
| OS | Ubuntu | Primary target |
| Deployment | `install.sh` script | One-command setup from GitHub |
| Log retention | 3 days detailed, permanent aggregated | Keeps DB small, preserves historical stats |

---

## 1. Transparent Proxy Principle

Airoxy behaves as a **byte-for-byte transparent proxy** for Anthropic's `/v1/messages` endpoint. Clients change only the base URL and `x-api-key` value — no other code changes needed.

### 1.1 Request Flow

```
Client                    Airoxy                         Anthropic
  |                         |                               |
  |-- POST /v1/messages --> |                               |
  |   x-api-key: <token>   |                               |
  |                         |-- Authenticate token          |
  |                         |-- Select API key (round-robin)|
  |                         |-- POST /v1/messages --------> |
  |                         |   x-api-key: <anthropic_key>  |
  |                         |                               |
  |                         | <---- Response/SSE stream --- |
  | <-- Forward as-is ---- |                               |
  |                         |-- Log usage                   |
```

### 1.2 Header Transformation

**Incoming → Outgoing:**
- `x-api-key: <airoxy_token>` → removed, used for auth; replaced with `x-api-key: <selected_anthropic_key>`
- `anthropic-version` → forwarded if present; default from `config('airoxy.anthropic_version')` added if absent (initially `2023-06-01`)
- `anthropic-beta`, `content-type`, and other Anthropic headers → forwarded as-is

**Response:** HTTP status code, body, and Anthropic response headers (especially rate-limit headers) forwarded as-is to client.

### 1.3 Body Handling

The raw request body (`request()->getContent()`) is forwarded **verbatim** to Anthropic — no re-serialization. A separate JSON decode of the raw body extracts only `model` and `stream` for logging/routing. The forwarded payload uses `'body' => $rawBody` with Guzzle (not `'json'`), preserving key ordering, whitespace, and numeric precision byte-for-byte. All Anthropic parameters pass through transparently.

---

## 2. Architecture

### 2.1 Server: Laravel Octane + FrankenPHP

```
airoxy:serve
  └── php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=3800 --workers=auto
```

- Standalone process, does not affect other PHP/Laravel apps on the server
- Workers scale to CPU cores automatically
- App stays in memory — no per-request bootstrap overhead
- Native streaming SSE support without buffer issues

### 2.2 File Structure

```
airoxy/
├── app/
│   ├── Console/Commands/
│   │   ├── ServeCommand.php              # airoxy:serve (wraps octane:start)
│   │   ├── ApiKeyAddCommand.php          # api-key:add
│   │   ├── ApiKeyListCommand.php         # api-key:list
│   │   ├── ApiKeyRemoveCommand.php       # api-key:remove
│   │   ├── ApiKeyToggleCommand.php       # api-key:toggle
│   │   ├── TokenAddCommand.php           # token:add
│   │   ├── TokenListCommand.php          # token:list
│   │   ├── TokenRemoveCommand.php        # token:remove
│   │   ├── TokenRefreshCommand.php       # token:refresh
│   │   ├── TokenAutoCommand.php          # token:auto
│   │   ├── LogsCommand.php               # logs
│   │   └── StatsCommand.php              # stats
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── ProxyController.php
│   │   └── Middleware/
│   │       └── AuthenticateToken.php
│   ├── Models/
│   │   ├── AnthropicApiKey.php
│   │   ├── AccessToken.php
│   │   ├── RequestLog.php
│   │   └── DailyStat.php
│   └── Services/
│       ├── ProxyService.php              # Orchestrates proxy flow
│       ├── StreamHandler.php             # SSE streaming + token parsing
│       ├── ApiKeyRotator.php             # Round-robin key selection
│       ├── TokenRefresher.php            # OAuth2 token refresh
│       └── CostCalculator.php            # Cost estimation
├── config/
│   └── airoxy.php                        # Pricing, OAuth2 constants, settings
├── database/
│   └── migrations/
│       ├── create_anthropic_api_keys_table.php
│       ├── create_access_tokens_table.php
│       ├── create_request_logs_table.php
│       └── create_daily_stats_table.php
├── routes/
│   └── api.php                           # POST /v1/messages
├── supervisor/
│   └── airoxy-worker.conf
├── bin/
│   └── airoxy                            # Global alias script
└── install.sh                            # One-command deployment
```

### 2.3 Service Responsibilities

| Service | Responsibility |
|---------|---------------|
| `ProxyService` | Receives validated request, selects API key, delegates to streaming or non-streaming flow, triggers logging |
| `StreamHandler` | Opens streaming connection to Anthropic, forwards SSE chunks in real-time, parses token usage from events without blocking forwarding |
| `ApiKeyRotator` | Selects next active API key via round-robin (`last_used_at` ordering), handles retry on 429/529 |
| `TokenRefresher` | Calls OAuth2 refresh endpoint, updates token/refresh_token/expiry, deactivates after 3 consecutive failures |
| `CostCalculator` | Computes estimated cost from model name and token counts using config pricing |

---

## 3. Database Design

### 3.1 `anthropic_api_keys`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | auto-increment |
| name | string, nullable | Descriptive label |
| api_key | string, encrypted | Laravel encrypted casting |
| is_active | boolean, default true | |
| last_used_at | timestamp, nullable | For round-robin ordering |
| usage_order | integer | Explicit ordering |
| created_at | timestamp | |
| updated_at | timestamp | |

### 3.2 `access_tokens`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | auto-increment |
| name | string, nullable | Descriptive label |
| token | string, unique, indexed | The `x-api-key` value clients send |
| refresh_token | string | OAuth2 refresh token |
| token_expires_at | timestamp | Computed from `expires_in` |
| is_active | boolean, default true | |
| last_used_at | timestamp, nullable | |
| refresh_fail_count | integer, default 0 | Consecutive failures |
| created_at | timestamp | |
| updated_at | timestamp | |

### 3.3 `request_logs`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | auto-increment |
| access_token_id | bigint FK | |
| api_key_id | bigint FK | |
| model | string | e.g. "claude-sonnet-4-6" |
| endpoint | string | Always "/v1/messages" |
| is_stream | boolean | |
| input_tokens | integer, nullable | |
| output_tokens | integer, nullable | |
| cache_creation_input_tokens | integer, nullable | |
| cache_read_input_tokens | integer, nullable | |
| estimated_cost_usd | decimal(10,8), nullable | |
| status_code | integer | |
| error_message | text, nullable | |
| duration_ms | integer | |
| requested_at | timestamp | When the client request arrived |
| created_at | timestamp | When the log row was inserted (after proxy completes). No `updated_at` (append-only) |

**Retention:** Rows older than 3 days are purged daily by a scheduled task, after aggregation.

**Index:** `requested_at` is indexed for date-range queries and purge operations.

### 3.4 `daily_stats`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | auto-increment |
| date | date, indexed | The day being aggregated |
| access_token_id | bigint FK, nullable | null = all tokens combined |
| model | string, nullable | null = all models combined |
| total_requests | integer | |
| successful_requests | integer | |
| failed_requests | integer | |
| total_input_tokens | bigint | |
| total_output_tokens | bigint | |
| total_cache_creation_tokens | bigint | |
| total_cache_read_tokens | bigint | |
| total_estimated_cost_usd | decimal(12,8) | |
| created_at | timestamp | |
| updated_at | timestamp | |

**Unique constraint:** `(date, access_token_id, model)` — one row per day/token/model combination, plus rollup rows where token or model is null.

---

## 4. Proxy Flow (Non-Streaming & Streaming)

### 4.1 Two-Phase Approach

`ProxyService` uses a two-phase approach to correctly handle both streaming and non-streaming, and to forward the correct HTTP status code:

```
Phase 1: Make upstream request to Anthropic
  ├── stream=true  → Guzzle with 'stream' => true (deferred body read)
  └── stream=false → Guzzle standard request (full response buffered)

Phase 2: Inspect upstream response status
  ├── 4xx/5xx error → Return error response directly (correct status code, body as-is)
  └── 2xx success
       ├── stream=true  → Return StreamedResponse, forward chunks
       └── stream=false → Return full response (status, body, headers as-is)
```

This ensures the client always receives the correct HTTP status code from Anthropic, even for streaming requests.

### 4.2 Non-Streaming Proxy

When `stream` is `false` or absent:

```php
$response = Http::withHeaders($proxyHeaders)
    ->withBody($rawBody, 'application/json')
    ->withOptions(['connect_timeout' => 10, 'timeout' => 300])
    ->post('https://api.anthropic.com/v1/messages');

// Extract usage from JSON response for logging
$responseBody = $response->body();
$responseData = json_decode($responseBody, true);
// Log: input_tokens, output_tokens, cache tokens from $responseData['usage']

return response($responseBody, $response->status())
    ->withHeaders($this->forwardHeaders($response));
```

Note: `withBody($rawBody, 'application/json')` sends the raw bytes without re-serialization.

### 4.3 Streaming SSE Proxy

This is the most critical component. The proxy must forward SSE chunks in real-time without any buffering.

#### 4.3.1 Flow

```
Anthropic SSE stream
    ↓ (chunk received)
    ├── 1. echo $chunk to client (IMMEDIATE)
    ├── 2. flush()
    └── 3. Parse for token usage (non-blocking, after echo)
```

#### 4.3.2 Implementation Approach

Using Laravel's HTTP client (Octane-aware, avoids Guzzle memory leaks):

```php
// Phase 1: Make the upstream request
$upstream = Http::withHeaders($proxyHeaders)
    ->withBody($rawBody, 'application/json')
    ->withOptions([
        'stream' => true,
        'read_timeout' => 300,
        'connect_timeout' => 10,
    ])
    ->post('https://api.anthropic.com/v1/messages');

// Phase 2: Check status before streaming
if ($upstream->status() !== 200) {
    // Error before stream started — forward as regular response
    return response($upstream->body(), $upstream->status())
        ->withHeaders($this->forwardHeaders($upstream));
}

// Phase 2b: Stream success response
return response()->stream(function () use ($upstream, &$logData) {
    $body = $upstream->toPsrResponse()->getBody();
    while (!$body->eof()) {
        $chunk = $body->read(8192);
        if ($chunk !== '' && $chunk !== false) {
            echo $chunk;
            flush();
            $this->parseSSEChunk($chunk, $tokenTracker);
        }
    }
}, 200, [
    'Content-Type' => 'text/event-stream',
    'Cache-Control' => 'no-cache, no-store, must-revalidate',
    'Connection' => 'keep-alive',
    'X-Accel-Buffering' => 'no',
]);
```

### 4.4 SSE Token Parsing

- `message_start` → extract `message.usage.input_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens`
- `message_delta` → extract `usage.output_tokens`
- Parser maintains an incomplete-event buffer for chunks that split across SSE event boundaries
- Parsing never blocks chunk forwarding

### 4.5 Error Handling

| Scenario | Action |
|----------|--------|
| Anthropic returns 4xx/5xx before stream starts | Forward error response with correct status code and body, log |
| Anthropic connection drops mid-stream | Log with partial data, set error_message |
| Client disconnects mid-stream | Detect via `connection_aborted()`, close Anthropic connection, log partial data |
| API key gets 429/529 | Retry with next key; if all exhausted, forward error |

---

## 5. API Key Rotation (Round-Robin)

### 5.1 Selection Algorithm

```
1. Begin DB::transaction()
2. Query active keys ordered by last_used_at ASC (nulls first), LIMIT 1
3. Update selected key's last_used_at = now()
4. Commit transaction
5. If request fails with 429/529, retry with next key (exclude failed key IDs)
6. If all keys exhausted, return last error to client
```

Uses `DB::transaction()` for atomicity. SQLite serializes write transactions, so concurrent requests naturally take turns. **Important:** SQLite's `busy_timeout` must be set to a reasonable value (e.g. 5000ms) in `config/database.php` to avoid `SQLITE_BUSY` errors under concurrent load. Without this, the default 0ms timeout causes immediate failures. The `usage_order` column serves as tiebreaker when multiple keys have the same `last_used_at` (e.g. all null on first use): keys are ordered by `last_used_at ASC, usage_order ASC`.

---

## 6. Access Token & OAuth2 Refresh

### 6.1 Authentication Flow

```
Request arrives
  → Extract x-api-key header
  → Missing? → 401
  → Lookup in access_tokens where is_active = true
  → Not found? → 401
  → Token expired (token_expires_at < now)? → 401
  → Valid → proceed, update last_used_at

401 response body mimics Anthropic's error format for client compatibility:
```json
{"type": "error", "error": {"type": "authentication_error", "message": "Invalid API key"}}
```

### 6.2 Token Refresh

**Schedule:** Every 6 hours (configurable via `AIROXY_TOKEN_REFRESH_INTERVAL` in minutes).

**OAuth2 endpoint:**
```
POST https://platform.claude.com/v1/oauth/token
{
    "grant_type": "refresh_token",
    "refresh_token": "<current_refresh_token>",
    "client_id": "9d1c250a-e61b-44d9-88ed-5944d1962f5e",
    "scope": "user:profile user:inference"
}
```

`client_id` and `scope` are constants stored in `config/airoxy.php`.

**On success:** Update `token`, `refresh_token`, `token_expires_at` (now + expires_in seconds), reset `refresh_fail_count` to 0.

**On failure:** Increment `refresh_fail_count`. After 3 consecutive failures → set `is_active = false`, log warning.

### 6.3 `token:auto` — Credential Discovery

Scans:
1. `/root/.claude/.credentials.json`
2. `/home/*/.claude/.credentials.json`

Reads `claudeAiOauth.accessToken`, `claudeAiOauth.refreshToken`, `claudeAiOauth.expiresAt` (millisecond Unix timestamp). Names derived from Unix username in the path.

**Deduplication:** Matches by `refresh_token` (stable across refreshes). If found, updates `token` and `token_expires_at` with latest values. If not found, creates new record. This handles the case where a token was already imported but has since been refreshed locally by Claude Code.

---

## 7. Cost Calculation

Pricing stored in `config/airoxy.php`, easily updatable:

```
cost = (input_tokens / 1M * input_price)
     + (output_tokens / 1M * output_price)
     + (cache_creation_tokens / 1M * input_price * 1.25)
     + (cache_read_tokens / 1M * input_price * 0.10)
```

Unknown models fall back to `default` pricing.

---

## 8. Log Retention & Statistics Aggregation

### 8.1 Daily Scheduler Tasks

Two tasks run daily (via Laravel scheduler):

1. **Aggregate** (runs first, at 01:00 UTC): For each completed day (`date < today`), group `request_logs` by `(date, access_token_id, model)` and insert/update into `daily_stats`. Also creates rollup rows with null token/model for totals. Only aggregates days not yet present in `daily_stats`.

2. **Purge** (runs after aggregate, at 01:30 UTC): Delete `request_logs` where `requested_at < now() - 3 days`.

### 8.2 Stats Command Behavior

`airoxy stats` reads from:
- `request_logs` for recent data (last 3 days)
- `daily_stats` for historical data
- Combines both for complete picture

---

## 9. CLI Commands

All commands prefixed with `airoxy` via the global alias (`/usr/local/bin/airoxy`).

| Command | Description |
|---------|-------------|
| `airoxy serve` | Start Octane/FrankenPHP server |
| `airoxy api-key:add {key} --name=` | Add Anthropic API key |
| `airoxy api-key:list` | List keys (masked, last 8 chars visible) |
| `airoxy api-key:remove {id}` | Remove key |
| `airoxy api-key:toggle {id}` | Toggle active/inactive |
| `airoxy token:add {token} {refresh_token} --name= --expires-in=28800` | Add access token |
| `airoxy token:list` | List tokens (first 8 + last 4 chars visible) |
| `airoxy token:remove {id}` | Remove token |
| `airoxy token:refresh --id=` | Refresh one or all tokens |
| `airoxy token:auto --dry-run --path=` | Auto-import from .credentials.json files |
| `airoxy logs --limit=50 --token= --today --date=` | View request logs |
| `airoxy stats --today --month --token=` | View statistics |

---

## 10. Installation Script (`install.sh`)

The script lives at project root and is invoked:
```bash
curl -fsSL https://raw.githubusercontent.com/oralunal/airoxy/main/install.sh | bash
```

### 10.1 What It Does

```
1. Preflight checks
   ├── Verify Ubuntu
   ├── Verify PHP >= 8.5
   ├── Verify required PHP extensions (curl, mbstring, sqlite3, openssl, tokenizer, xml)
   └── Verify composer is installed (install if missing)

2. Clone & setup
   ├── git clone https://github.com/oralunal/airoxy.git /var/www/airoxy
   ├── cd /var/www/airoxy
   ├── composer install --no-dev --optimize-autoloader
   ├── cp .env.example .env
   ├── php artisan key:generate
   └── touch database/database.sqlite && php artisan migrate --force

3. Install FrankenPHP / Octane
   └── php artisan octane:install --server=frankenphp --no-interaction

4. Permissions
   ├── chown -R www-data:www-data /var/www/airoxy
   └── chmod -R 775 storage bootstrap/cache database

5. Global alias
   └── Install /usr/local/bin/airoxy shell script

6. Supervisor
   ├── Copy supervisor/airoxy-worker.conf to /etc/supervisor/conf.d/
   ├── supervisorctl reread
   └── supervisorctl update

7. Summary
   └── Print status, next steps (add API keys, add tokens, start server)
```

### 10.2 Global Alias Script (`bin/airoxy`)

```bash
#!/bin/bash
AIROXY_PATH="/var/www/airoxy"
cd "$AIROXY_PATH" && php artisan "airoxy:$1" "${@:2}"
```

This prepends `airoxy:` to the first argument, so:
- `airoxy serve` → `php artisan airoxy:serve`
- `airoxy api-key:add KEY` → `php artisan airoxy:api-key:add KEY`
- `airoxy token:list` → `php artisan airoxy:token:list`
- `airoxy logs --today` → `php artisan airoxy:logs --today`
- `airoxy stats` → `php artisan airoxy:stats`

---

## 11. Configuration (`config/airoxy.php`)

Contains:
- **Pricing table:** Per-model input/output prices, cache multipliers, default fallback
- **OAuth2 constants:** endpoint URL, client_id, scope
- **Server defaults:** host, port (from .env)
- **Token refresh interval:** from .env (default 360 minutes)
- **Log retention days:** 3 (from .env)
- **Anthropic version default:** `2023-06-01` (configurable)

**Dependencies note:** `laravel/octane` must be added to `composer.json` `require` section. `composer.json` PHP requirement must be updated to `^8.5`.

---

## 12. Supervisor Configuration

Two processes:

```ini
[program:airoxy]
command=php /var/www/airoxy/artisan octane:start --server=frankenphp --host=0.0.0.0 --port=3800 --workers=auto
autostart=true
autorestart=true
user=www-data
stopwaitsecs=30

[program:airoxy-scheduler]
command=/bin/bash -c "while true; do php /var/www/airoxy/artisan schedule:run --no-interaction >> /dev/null 2>&1; sleep 60; done"
autostart=true
autorestart=true
user=www-data
stopwaitsecs=10
```

---

## 13. Security

- API keys encrypted at rest using Laravel's `encrypted` cast
- `access_tokens.token` stored in plaintext (not hashed) because the refresh mechanism replaces it in-place and it needs to be sent to Anthropic as a bearer token. Masked in all CLI output and logs (first 8 + last 4 chars visible)
- `access_tokens.refresh_token` stored in plaintext (required for OAuth2 refresh calls)
- Invalid token requests are not logged (spam protection)
- `x-api-key` header is required on all proxy requests
- `install.sh` sets proper file ownership (`www-data`) and permissions

---

## 14. .env Configuration

```env
APP_NAME=Airoxy

# Server
AIROXY_HOST=0.0.0.0
AIROXY_PORT=3800

# Token Refresh (minutes, default 360 = 6 hours)
AIROXY_TOKEN_REFRESH_INTERVAL=360

# Log Retention (days)
AIROXY_LOG_RETENTION_DAYS=3

# Database
DB_CONNECTION=sqlite
```

---

## 15. Route Definition

Two routes, no middleware stack except custom token auth on the proxy:

```
GET  /health       → returns {"status": "ok"} (no auth, for monitoring/load balancers)
POST /v1/messages  → ProxyController@handle (Middleware: AuthenticateToken)
```

No CSRF, no session, no cookies. API-only routes.
