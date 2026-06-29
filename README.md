# llmor CLI

A command-line client for [llmor.com](https://llmor.com) (the llmonrails backend).
It authenticates against the llmor API, persists the session locally, and
transparently re-authenticates when the session expires.

## Requirements

- PHP 8.3+ with the `curl`, `mbstring` and `json` extensions (for running from
  source or as a phar)
- [Composer](https://getcomposer.org/)

The self-contained binary has **no** runtime requirements.

## Installation

### From source

```bash
composer install
./bin/llmor list
```

### As a phar

```bash
composer build:phar          # produces build/llmor.phar
php build/llmor.phar list
```

### As a self-contained binary

Builds a statically-linked executable that runs without PHP installed, using
[static-php-cli](https://static-php.dev):

```bash
make binary                  # produces build/llmor
./build/llmor list
```

`scripts/build-binary.sh` will download the `spc` tool automatically if it is
not already on your `PATH`.

## Configuration & authentication

Credentials and the active session live in a `.llmor/` directory. The CLI looks
for one by walking up from the current directory, and falls back to `~/.llmor/`.
This lets you keep per-project credentials or a single global login.

Sign in interactively:

```bash
./bin/llmor auth:login                 # writes ./.llmor/.env in the current project
./bin/llmor auth:login --global        # writes ~/.llmor/.env instead
```

Or non-interactively (e.g. in CI):

```bash
./bin/llmor auth:login --host=https://llmor.com --email=you@example.com --password=secret
```

This creates `.llmor/.env`:

```dotenv
LLMOR_HOST=https://llmor.com
LLMOR_IDENTIFIER=you@example.com
LLMOR_SECRET=secret
```

Any `LLMOR_*` environment variable overrides the corresponding value in the
file, which is handy for CI.

> The `.llmor/` directory is git-ignored. Credentials are stored with `0600`
> permissions, as is the cached `session.json`.

## Usage

```bash
./bin/llmor auth:whoami              # show the authenticated user
./bin/llmor auth:whoami --json       # raw JSON
./bin/llmor auth:logout              # forget the cached session (keeps .env)
./bin/llmor conversations:list       # list conversations
```

## How authentication works

1. `POST /v1/auth/session` creates an anonymous session (`token` + `secret`).
2. `POST /v1/auth/signin` upgrades it to a signed-in session using your
   credentials.
3. Every request carries an `X-AccessToken` header — a base64-encoded JSON
   document `{token, timestamp, signature}` where
   `signature = HMAC-SHA256("METHOD:REQUEST_URI:TIMESTAMP", secret)`.
4. If the API returns `401`, the CLI discards the session, re-authenticates, and
   retries the request once.

The signing logic lives in `src/Auth/AccessTokenSigner.php` and mirrors the
server-side validation in llmonrails.

## Development

```bash
composer check     # cs (dry-run) + phpstan + phpunit
composer cs-fix    # apply coding-standard fixes
composer test
```

See `make help` for the full list of targets.
