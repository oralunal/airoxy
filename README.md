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
# 1. Add an access token (Anthropic OAuth token)
airoxy token:add sk-ant-oat01-xxx sk-ant-ort01-xxx --name="My Token"

# 2. Or auto-import from Claude Code credentials
airoxy token:auto

# 3. Create an API key for your client
airoxy api-key:add --name="My App"
# => ak-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx (shown once)

# 4. Start the server
sudo supervisorctl start airoxy
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

### Server

| Command | Description |
|---------|-------------|
| `airoxy serve` | Start the proxy server (reads host/port from .env) |

### API Keys (Client Authentication)

API keys are what your clients use to authenticate with Airoxy. They are auto-generated.

| Command | Description |
|---------|-------------|
| `airoxy api-key:add --name="My App"` | Create a new API key (auto-generated, shown once) |
| `airoxy api-key:list` | List all API keys (masked) |
| `airoxy api-key:remove {id}` | Delete an API key |
| `airoxy api-key:toggle {id}` | Enable/disable an API key |

### Access Tokens (Anthropic Authentication)

Access tokens are OAuth tokens that Airoxy uses to call Anthropic. Multiple tokens are rotated via round-robin. Tokens are refreshed automatically every 6 hours.

| Command | Description |
|---------|-------------|
| `airoxy token:add {token} {refresh_token} --name= --expires-in=28800` | Add a token manually |
| `airoxy token:auto --dry-run --path=` | Auto-import from `~/.claude/.credentials.json` files |
| `airoxy token:list` | List all tokens (masked) |
| `airoxy token:remove {id}` | Delete a token |
| `airoxy token:refresh --id=` | Refresh one or all tokens via OAuth2 |

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
| `sudo airoxy update` | Pull latest code, install deps, migrate, restart |
| `sudo airoxy doctor` | Check system health and auto-fix issues |

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

### Transparent Proxy

Request body is forwarded byte-for-byte to Anthropic. Airoxy only reads `model` and `stream` fields for logging. All Anthropic parameters (tools, thinking, metadata, etc.) pass through transparently.

### Round-Robin Token Rotation

When multiple access tokens are configured, Airoxy selects the least recently used active token for each request. If a token returns 429 (rate limit) or 529 (overloaded), the next token is tried automatically.

### OAuth2 Token Refresh

Access tokens are refreshed automatically every 6 hours (configurable). After 3 consecutive refresh failures, a token is deactivated. `airoxy token:auto` imports tokens from Claude Code credential files on the server.

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
