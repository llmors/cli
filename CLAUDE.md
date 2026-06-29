# llmor-cli — architecture notes

A PHP 8.3 / Symfony Console CLI client for the llmor.com (llmonrails) API.
Buildable to a phar (Box) or a self-contained static binary (static-php-cli).

## Layout

- `bin/llmor` — entry point; locates the autoloader and runs `Application`.
- `src/Application.php` — Symfony Console app; resolves config, wires `Services`,
  registers commands. Both `Services` and `ConfigResolver` are injectable for tests.
- `src/Services.php` — tiny explicit service container (no DI component, to keep
  the static binary small). Accepts an optional `HttpClientInterface` for tests.
- `src/Config/` — `ConfigResolver` finds the `.llmor` dir (walk up from CWD, else
  `~/.llmor`); `EnvFile` reads/writes the `.env`; `Configuration` is the resolved
  value object.
- `src/Auth/` — `Session` (value object), `SessionStore` (`session.json`, 0600),
  `AccessTokenSigner` (pure HMAC signer), `SessionManager` (create → sign-in →
  re-auth state machine).
- `src/Client/` — `LlmorClient` signs each request and retries once on 401;
  `ApiResponse` normalises the `{data, meta}` envelope; typed exceptions in
  `Client/Exception/`.
- `src/Command/` — Symfony commands (`auth:login`, `auth:logout`, `auth:whoami`,
  `conversations:list`). `AbstractCommand` holds shared error/format helpers.

## Auth protocol (mirrors llmonrails `src/Auth/SessionManager.php`)

1. `POST /v1/auth/session` → `{token, secret}` (may be nested under `data`).
2. Per request: `X-AccessToken = base64(json({token, timestamp, signature}))`,
   `signature = hash_hmac('sha256', "METHOD:REQUEST_URI:TIMESTAMP", secret)`.
   `REQUEST_URI` is path **plus query string**, no host — it must match the wire
   request exactly, so `LlmorClient::buildRequestUri()` signs the same string it sends.
3. `POST /v1/auth/signin` `{identifier, secret}` (signed) upgrades to a 30-day
   signed-in session. Tokens older than 3600s are rejected server-side.
4. `401` ⇒ discard session, re-authenticate, retry once.

## Conventions

- PHP 8.3, `declare(strict_types=1)`, PSR-4 namespace `Llmor\Cli\`.
- Coding standard: PHP-CS-Fixer `@PSR12` + `@Symfony` (`composer cs-fix`).
- Static analysis: PHPStan level 8 (`composer stan`).
- Tests: PHPUnit; use `MockHttpClient` for HTTP, temp dirs for config/session.
- `composer check` runs cs + stan + test; do this before committing.
- Never commit `.llmor/` or real credentials.
