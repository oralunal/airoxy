# Airoxy

Anthropic API proxy server. Authenticates clients with API keys, routes requests to Anthropic using round-robin access token rotation, and logs usage with cost estimation. Streaming SSE supported.

Clients change only the base URL and `x-api-key` header to use Airoxy — no other code changes needed.

```
Client ──► Airoxy ──► Anthropic
          (auth)    (round-robin)
```

## Requirements

- Ubuntu

Everything else is auto-installed: PHP 8.5+ (with extensions), Git, Composer, and Supervisor.

## Installation

```bash
curl -fsSL https://raw.githubusercontent.com/oralunal/airoxy/main/install.sh | sudo bash
```

This clones the repo to `/var/www/airoxy`, installs dependencies, sets up FrankenPHP/Octane, configures Supervisor, and creates the global `airoxy` command.

## Quick Start

```bash
# 1. Add an access token
#    Standard API key:
airoxy token:add sk-ant-api03-xxx --name="API Key"

#    Or OAuth token (Claude subscription):
airoxy token:add sk-ant-oat01-xxx sk-ant-ort01-xxx --name="OAuth Token"

#    Or auto-import OAuth tokens from Claude Code credentials:
airoxy token:auto

# 2. Create an API key for your client
airoxy api-key:add --name="My App"
# => ak-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx (shown once)

# 3. Start the server
sudo airoxy start
```

Your client can now send requests to `http://your-server:3800/v1/messages` with the generated API key.

## Configuration

Edit `/var/www/airoxy/.env`:

```env
AIROXY_HOST=0.0.0.0         # Listen address
AIROXY_PORT=3800             # Listen port
AIROXY_TOKEN_REFRESH_INTERVAL=360  # Token refresh interval (minutes)
AIROXY_LOG_RETENTION_DAYS=3  # Days to keep detailed logs
```

## Commands

All commands are available via the `airoxy` global alias. System commands (`update`, `doctor`) require root.

### Service

| Command | Description |
|---------|-------------|
| `sudo airoxy start` | Start Airoxy services |
| `sudo airoxy stop` | Stop Airoxy services |
| `sudo airoxy restart` | Restart Airoxy services |
| `airoxy status` | Show service status |

### API Keys (Client Authentication)

API keys are what your clients use to authenticate with Airoxy. They are auto-generated.

| Command | Description |
|---------|-------------|
| `airoxy api-key:add --name="My App"` | Create a new API key (auto-generated, shown once) |
| `airoxy api-key:list` | List all API keys (masked) |
| `airoxy api-key:remove {id}` | Delete an API key |
| `airoxy api-key:toggle {id}` | Enable/disable an API key |

### Access Tokens (Anthropic Authentication)

Airoxy supports two token types, auto-detected from the prefix:

- **API keys** (`sk-ant-api03-*`): Transparent proxy — `x-api-key` header, no modifications
- **OAuth tokens** (`sk-ant-oat-*`): Subscription proxy — Bearer auth, automatic refresh every 6 hours

Both types participate in the same round-robin pool.

| Command | Description |
|---------|-------------|
| `airoxy token:add {token} {refresh_token?} --name= --expires-in=28800` | Add a token (refresh_token required for OAuth only) |
| `airoxy token:auto --dry-run --path=` | Auto-import OAuth tokens from `~/.claude/.credentials.json` |
| `airoxy token:list` | List all tokens (masked, with type) |
| `airoxy token:remove {id}` | Delete a token |
| `airoxy token:refresh --id=` | Refresh OAuth tokens via OAuth2 |

### Logs & Statistics

| Command | Description |
|---------|-------------|
| `airoxy logs` | Show last 50 request logs |
| `airoxy logs --limit=100` | Show last 100 logs |
| `airoxy logs --today` | Today's logs only |
| `airoxy logs --date=2026-04-03` | Specific date |
| `airoxy logs --token={id}` | Filter by access token |
| `airoxy stats` | Overall statistics |
| `airoxy stats --today` | Today's stats |
| `airoxy stats --month` | This month's stats |

### System Management

| Command | Description |
|---------|-------------|
| `sudo airoxy update` | Update to latest release, migrate, restart |
| `sudo airoxy doctor` | Check system health and auto-fix issues |
| `sudo airoxy uninstall` | Remove Airoxy completely (with confirmation) |

#### Doctor Checks

`airoxy doctor` verifies and auto-fixes:

- PHP version (8.5+)
- PHP extensions (curl, mbstring, sqlite3, openssl, tokenizer, xml)
- Composer installation
- Supervisor installation and config
- `.env` file and `APP_KEY`
- SQLite database existence and permissions
- Pending migrations
- FrankenPHP installation
- File ownership (www-data)
- Airoxy and scheduler process status
- Global alias

## Usage Example

```bash
curl http://your-server:3800/v1/messages \
  -H "content-type: application/json" \
  -H "x-api-key: ak-your-api-key-here" \
  -H "anthropic-version: 2023-06-01" \
  -d '{
    "model": "claude-sonnet-4-6",
    "max_tokens": 1024,
    "messages": [{"role": "user", "content": "Hello!"}]
  }'
```

Streaming:

```bash
curl -N http://your-server:3800/v1/messages \
  -H "content-type: application/json" \
  -H "x-api-key: ak-your-api-key-here" \
  -H "anthropic-version: 2023-06-01" \
  -d '{
    "model": "claude-sonnet-4-6",
    "max_tokens": 1024,
    "stream": true,
    "messages": [{"role": "user", "content": "Hello!"}]
  }'
```

## How It Works

### Token Types

**API keys** (`sk-ant-api03-*`) get a fully transparent proxy: requests are forwarded with `x-api-key` header, no body or header modifications. Client headers like `anthropic-beta` and `anthropic-version` are passed through as-is.

**OAuth tokens** (`sk-ant-oat-*`) use subscription-specific auth: `Authorization: Bearer` header, required beta flags, and Claude Code system prompt injection. This allows using Claude Pro/Max subscriptions as an API.

### Transparent Proxy

Request body is forwarded byte-for-byte to Anthropic (for API key tokens) or with minimal modifications (for OAuth tokens). Airoxy only reads `model` and `stream` fields for logging. All Anthropic parameters (tools, thinking, metadata, etc.) pass through transparently.

### Round-Robin Token Rotation

When multiple access tokens are configured, Airoxy selects the least recently used active token for each request. If a token returns 429 (rate limit) or 529 (overloaded), the next token is tried automatically. Both API key and OAuth tokens participate in the same pool.

### OAuth2 Token Refresh

OAuth tokens are refreshed automatically every 6 hours (configurable). After 3 consecutive refresh failures, a token is deactivated. API key tokens do not expire and are not refreshed. `airoxy token:auto` imports OAuth tokens from Claude Code credential files on the server.

### Cost Estimation

Each request is logged with estimated cost based on token usage and model pricing. Pricing is configured in `config/airoxy.php`.

### Log Retention

Detailed request logs are kept for 3 days (configurable). Before purging, logs are aggregated into daily statistics that are kept permanently.

## Architecture

- **Server**: Laravel Octane + FrankenPHP
- **Database**: SQLite
- **Process Manager**: Supervisor

```
airoxy/
  app/Services/
    ProxyService.php          # Core proxy logic (stream + non-stream)
    StreamHandler.php         # SSE chunk parsing for token counting
    AccessTokenRotator.php    # Round-robin token selection
    TokenRefresher.php        # OAuth2 token refresh
    CostCalculator.php        # Cost estimation
  app/Http/
    Middleware/AuthenticateToken.php  # API key validation
    Controllers/ProxyController.php  # Request handler
  app/Console/Commands/       # All CLI commands
  config/airoxy.php           # Pricing, OAuth2 config, settings
```

## Health Check

```
GET http://your-server:3800/health
```

Returns the application status. No authentication required.

## License

MIT
