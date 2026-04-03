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
- `anthropic-version` → forwarded if present; default `2023-06-01` added if absent
- `anthropic-beta`, `content-type`, and other Anthropic headers → forwarded as-is

**Response:** HTTP status code, body, and Anthropic response headers (especially rate-limit headers) forwarded as-is to client.

### 1.3 Body Handling

Request body is **never parsed or modified** for proxying purposes. Only `model` and `stream` fields are read for logging. The entire body is forwarded byte-for-byte. All Anthropic parameters (model, messages, tools, thinking, metadata, container, etc.) pass through transparently.

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
| requested_at | timestamp | |
| created_at | timestamp | |

**Retention:** Rows older than 3 days are purged daily by a scheduled task, after aggregation.

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

## 4. Streaming SSE Proxy

This is the most critical component. The proxy must forward SSE chunks in real-time without any buffering.

### 4.1 Flow

```
Anthropic SSE stream
    ↓ (chunk received)
    ├── 1. echo $chunk to client (IMMEDIATE)
    ├── 2. flush()
    └── 3. Parse for token usage (non-blocking, after echo)
```

### 4.2 Implementation Approach

Using Laravel's `StreamedResponse` within Octane:

```php
return response()->stream(function () use ($proxyHeaders, $requestBody, &$logData) {
    $client = new \GuzzleHttp\Client();
    $response = $client->post('https://api.anthropic.com/v1/messages', [
        'headers' => $proxyHeaders,
        'json' => $requestBody,
        'stream' => true,
        'read_timeout' => 300,
        'connect_timeout' => 10,
    ]);

    $body = $response->getBody();
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

### 4.3 SSE Token Parsing

- `message_start` → extract `message.usage.input_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens`
- `message_delta` → extract `usage.output_tokens`
- Parser maintains an incomplete-event buffer for chunks that split across SSE event boundaries
- Parsing never blocks chunk forwarding

### 4.4 Error Handling

| Scenario | Action |
|----------|--------|
| Anthropic connection drops mid-stream | Log with partial data, set error_message |
| Client disconnects mid-stream | Detect via `connection_aborted()`, close Anthropic connection, log partial data |
| Anthropic returns 4xx/5xx before stream starts | Forward error response to client, log |
| API key gets 429/529 | Retry with next key; if all exhausted, forward error |

---

## 5. API Key Rotation (Round-Robin)

### 5.1 Selection Algorithm

```
1. Query active keys ordered by last_used_at ASC (nulls first)
2. Select first key
3. Atomically update last_used_at = now() (prevents race conditions)
4. If request fails with 429/529, mark key as temporarily skipped, try next
5. If all keys exhausted, return last error to client
```

Atomic selection uses `UPDATE ... WHERE id = ? AND last_used_at = ?` to prevent race conditions under concurrent requests.

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

Reads `claudeAiOauth.accessToken`, `claudeAiOauth.refreshToken`, `claudeAiOauth.expiresAt` (millisecond Unix timestamp). Deduplicates by `accessToken`. Names derived from Unix username in the path.

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

1. **Aggregate** (runs first): For each completed day, group `request_logs` by `(date, access_token_id, model)` and insert/update into `daily_stats`. Also creates rollup rows with null token/model for totals.

2. **Purge** (runs after aggregate): Delete `request_logs` where `created_at < now() - 3 days`.

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
cd "$AIROXY_PATH" && php artisan "$@"
```

Mapped so `airoxy serve` runs `php artisan airoxy:serve`, etc. The command prefix mapping:
- `airoxy serve` → `php artisan airoxy:serve`
- `airoxy api-key:*` → `php artisan airoxy:api-key:*`
- `airoxy token:*` → `php artisan airoxy:token:*`
- `airoxy logs` → `php artisan airoxy:logs`
- `airoxy stats` → `php artisan airoxy:stats`

---

## 11. Configuration (`config/airoxy.php`)

Contains:
- **Pricing table:** Per-model input/output prices, cache multipliers, default fallback
- **OAuth2 constants:** endpoint URL, client_id, scope
- **Server defaults:** host, port (from .env)
- **Token refresh interval:** from .env (default 360 minutes)
- **Log retention days:** 3

---

## 12. Supervisor Configuration

Two processes:

```ini
[program:airoxy]
command=php /var/www/airoxy/artisan octane:start --server=frankenphp --host=0.0.0.0 --port=3800 --workers=auto
autostart=true
autorestart=true
user=www-data
stopwaitsecs=3600

[program:airoxy-scheduler]
command=/bin/bash -c "while true; do php /var/www/airoxy/artisan schedule:run --no-interaction >> /dev/null 2>&1; sleep 60; done"
autostart=true
autorestart=true
user=www-data
```

---

## 13. Security

- API keys encrypted at rest using Laravel's `encrypted` cast
- Access tokens stored in plaintext (needed for OAuth2 refresh) but masked in all CLI output and logs
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

Single route, no middleware stack except custom token auth:

```
POST /v1/messages → ProxyController@handle
  Middleware: AuthenticateToken
```

No CSRF, no session, no cookies. API-only route.
