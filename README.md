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

Credentials and the active session live in a `.llmor/` directory. The CLI reads
**two** of them and layers the values **per key**: it walks up from the current
directory to find a project `.llmor/.env`, and falls back to `~/.llmor/.env` for
any value the project doesn't set. Real `LLMOR_*` environment variables override
both.

So you can keep your credentials global and override just one value per project —
for example email + secret in `~/.llmor/.env` and a project-specific
`LLMOR_VENDOR` in `./.llmor/.env`. (An empty value, e.g. `LLMOR_VENDOR=`, counts
as "not set" and falls back.) The session is stored in the project `.llmor/`
when one exists, otherwise in `~/.llmor/`.

Sign in interactively:

```bash
./bin/llmor auth:login                 # writes ./.llmor/.env in the current project
./bin/llmor auth:login --global        # writes ~/.llmor/.env instead
```

Or non-interactively (e.g. in CI):

```bash
./bin/llmor auth:login --host=https://llmor.com --email=you@example.com --password=secret --vendor=your-vendor-key
```

This creates `.llmor/.env`:

```dotenv
LLMOR_HOST=https://llmor.com
LLMOR_IDENTIFIER=you@example.com
LLMOR_SECRET=secret
LLMOR_VENDOR=your-vendor-key
```

Any `LLMOR_*` environment variable overrides the corresponding value in the
file, which is handy for CI.

### Choosing a vendor

Nearly every llmor resource is scoped to a vendor, so the CLI sends your
configured **vendor key** as the `X-Vendor` header on every authenticated
request (the same value the web app uses). `LLMOR_VENDOR` is the vendor key as
shown in the llmor web app — not the numeric id from a URL.

It is optional: commands that don't need a vendor (such as `auth:whoami`) work
without it, but most resource commands will return an error until one is set.
You can override it per run with the `LLMOR_VENDOR` environment variable.

> The `.llmor/` directory is git-ignored. Credentials are stored with `0600`
> permissions, as is the cached `session.json`.

## Usage

```bash
./bin/llmor auth:whoami              # show the authenticated user
./bin/llmor auth:whoami --json       # raw JSON
./bin/llmor auth:logout              # forget the cached session (keeps .env)
./bin/llmor conversations:list       # list conversations
```

## Functions: declarative sync & run

Declare your vendor functions in an `llmor.scsc` manifest at your project root
([SchemaScript](https://github.com/ClanCats/SchemaScript) syntax) and keep their
source under version control instead of editing in the web UI:

```scsc
pjas_silicon_docs: Function {
  [name]        = 'PJAS Silicon Docs'
  [description] = 'A collection of documentation for PJAS Silicon.'
  [runtime]     = 'silicon'

  [srcdir]      = './llmfnc/silicondocs/'
  [entry]       = 'main.lua'

  @path('docs/')
  [copy]        = { '../README.md' }
}
```

- The **declaration name** (`pjas_silicon_docs`) becomes the remote `function_key`.
- `[entry]` is the function's main script — its contents become the function `code`.
- Every **other** file under `[srcdir]` is synced as an auxiliary file the script can
  read at runtime via `file('relative/path')`. Binary (non-UTF-8) files are skipped.
- `[runtime]` is `silicon` or `graph`.
- `[copy]` pulls files from **outside** `[srcdir]` into the function. Sources resolve
  relative to the manifest, and the optional `@path('dir/')` sets the destination
  directory (the source's basename is appended) — so the example lands the project
  README at `docs/README.md`. Copied files sync like any other (hashing, `--prune`).

```bash
./bin/llmor sync                     # create/update every function + mirror its files
./bin/llmor sync --function pjas_silicon_docs   # just one
./bin/llmor sync --dry-run           # show what would change, apply nothing
./bin/llmor sync --prune             # also delete remote files with no local counterpart
./bin/llmor sync --json              # machine-readable result

./bin/llmor run                                   # list the functions you can run
./bin/llmor run pjas_silicon_docs                 # sync that function, then execute it
./bin/llmor run pjas_silicon_docs --arg q=hello   # pass arguments (repeatable)
./bin/llmor run pjas_silicon_docs --input '{"q":"hi","n":3}'   # JSON args (nested/typed)
./bin/llmor run pjas_silicon_docs --config-json '{"verbose":true}'
./bin/llmor run pjas_silicon_docs --no-sync       # run the already-synced version
./bin/llmor run pjas_silicon_docs --json          # raw run response
```

`run` syncs first because the server executes against the function's *persisted*
files. `sync` only writes when something actually changed, so re-running it on an
unchanged project is a no-op. Both commands resolve your configured vendor **key**
to its numeric id (functions are pathed by id) and require `LLMOR_VENDOR` to be set.

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
