# Support Different Token Types

## Problem

Airoxy only supports OAuth access tokens (`sk-ant-oat-xxx`) for proxying requests to Anthropic. This limits usage to Claude subscription accounts only.

Currently `buildProxyHeaders()` unconditionally sends `Authorization: Bearer` and OAuth beta flags for all tokens, while `prepareRequestBody()` already checks the `sk-ant-oat` prefix. This inconsistency means API keys would be sent with wrong headers. This change fixes both paths to be type-aware.

## Solution

Support standard Anthropic API keys (`sk-ant-api03-xxx`) alongside OAuth tokens. The proxy detects the token type and adjusts request handling accordingly. Both types participate in the same round-robin pool.

## Design

### Database

Add `type` column to `access_tokens` table:

- Values: `oauth`, `api_key`
- Default: `oauth` (preserves existing tokens)
- Auto-detected from token prefix on insert
- Backfill: migration updates any existing rows where token does NOT start with `sk-ant-oat` to `api_key`
- Use a PHP enum `TokenType` (`OAuth`, `ApiKey`) for type safety

### ProxyService: Conditional Behavior by Token Type

Uses `AccessToken->type` (not raw prefix checks) for branching.

**OAuth tokens** — current behavior:
- `Authorization: Bearer {token}` header
- Merge `anthropic-beta` with required OAuth flags (`claude-code-20250219,oauth-2025-04-20`)
- Inject `user-agent: claude-cli/2.1.91 (external, cli)` and `x-app: cli`
- Auto-inject Claude Code system prompt if missing

**API key tokens** — transparent proxy:
- `x-api-key: {token}` header
- Forward client's `anthropic-beta` as-is (no merging)
- Forward client's `anthropic-version` as-is (no fallback)
- `content-type: application/json`
- No body modifications
- No extra headers

### TokenAddCommand

- `refresh_token` argument becomes optional
- Type auto-detected from token prefix (`sk-ant-oat` → `oauth`, else → `api_key`)
- Validate: reject tokens that don't start with `sk-ant-` (unrecognized format)
- If `oauth` type but no `refresh_token` provided → error
- If `api_key` type → `refresh_token` and `token_expires_at` are null, `refresh_fail_count` stays 0

### TokenListCommand

- Add `Type` column to table output

### TokenRefresher

- `refreshAll()` adds explicit `where('type', 'oauth')` filter
- Keep existing `whereNotNull('token_expires_at')` as defense-in-depth

### TokenRefreshCommand

- When `--id` targets an API key token, output warning and skip (don't attempt OAuth refresh)

### TokenAutoCommand

- No changes — imports from Claude Code credentials which are always OAuth

### AccessTokenRotator

- No changes — type-agnostic, both types in same pool
- `isValid()` already handles null `token_expires_at` correctly (returns true)

### AccessToken Model

- Add `TokenType` enum (`OAuth`, `ApiKey`)
- Add `type` to fillable with enum cast
- Add `isOauth(): bool` and `isApiKey(): bool` helpers
- Auto-detect type from token prefix in model `boot()` or via `creating` event

## Files Changed

1. `app/Enums/TokenType.php` — new enum
2. New migration: add `type` column to `access_tokens` (default `oauth`, backfill)
3. `app/Models/AccessToken.php` — add type enum cast, helpers, auto-detection
4. `app/Services/ProxyService.php` — conditional headers/body based on token type
5. `app/Services/TokenRefresher.php` — filter by `oauth` type
6. `app/Console/Commands/TokenAddCommand.php` — optional refresh_token, auto-detect type
7. `app/Console/Commands/TokenListCommand.php` — show type column
8. `app/Console/Commands/TokenRefreshCommand.php` — guard against API key tokens