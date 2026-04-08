# Support Different Token Types

## Problem

Airoxy only supports OAuth access tokens (`sk-ant-oat-xxx`) for proxying requests to Anthropic. This limits usage to Claude subscription accounts only.

## Solution

Support standard Anthropic API keys (`sk-ant-api03-xxx`) alongside OAuth tokens. The proxy detects the token type and adjusts request handling accordingly. Both types participate in the same round-robin pool.

## Design

### Database

Add `type` column to `access_tokens` table:

- Values: `oauth`, `api_key`
- Default: `oauth` (preserves existing tokens)
- Auto-detected from token prefix on insert

### ProxyService: Conditional Behavior by Token Type

**OAuth tokens (`sk-ant-oat*`)** — current behavior:
- `Authorization: Bearer {token}` header
- Merge `anthropic-beta` with required OAuth flags (`claude-code-20250219,oauth-2025-04-20`)
- Inject `user-agent: claude-cli/2.1.91 (external, cli)` and `x-app: cli`
- Auto-inject Claude Code system prompt if missing

**API key tokens** — transparent proxy:
- `x-api-key: {token}` header
- Forward client's `anthropic-beta` and `anthropic-version` as-is
- No body modifications
- No extra headers beyond `content-type` and `anthropic-version`

### TokenAddCommand

- `refresh_token` argument becomes optional
- Type auto-detected from token prefix (`sk-ant-oat` → `oauth`, else → `api_key`)
- If `oauth` type but no `refresh_token` provided → error
- If `api_key` type → no `token_expires_at` set

### TokenListCommand

- Add `Type` column to table output

### TokenRefresher

- `refreshAll()` adds explicit `where('type', 'oauth')` filter (API keys don't refresh)

### TokenAutoCommand

- No changes — it imports from Claude Code credentials which are always OAuth

### AccessTokenRotator

- No changes — type-agnostic, both types in same pool

### AccessToken Model

- Add `type` to fillable
- Add `isOauth(): bool` and `isApiKey(): bool` helpers

## Files Changed

1. New migration: add `type` column to `access_tokens` (default `oauth`)
2. `app/Models/AccessToken.php` — add type helpers
3. `app/Services/ProxyService.php` — conditional headers/body based on type
4. `app/Services/TokenRefresher.php` — filter by `oauth` type
5. `app/Console/Commands/TokenAddCommand.php` — optional refresh_token, auto-detect type
6. `app/Console/Commands/TokenListCommand.php` — show type column