# llmor CLI

A command-line client for [llmor.com](https://llmor.com) (the llmonrails backend).

## Requirements

- PHP 8.3+ with the `curl`, `mbstring` and `json` extensions (for running from
  source or as a phar)
- [Composer](https://getcomposer.org/)

The self-contained binary has **no** runtime requirements.

## Installation

### Install (recommended)

Download the latest phar and drop it on your `PATH` as `llmor` (requires PHP
8.3+ with `curl`, `mbstring` and `json`):

```bash
sudo curl -fsSL https://github.com/llmors/cli/releases/latest/download/llmor.phar -o /usr/local/bin/llmor && sudo chmod +x /usr/local/bin/llmor
```

Then run `llmor list` to confirm. Linux users who want **no** runtime
dependency can grab the self-contained binary instead:

```bash
sudo curl -fsSL https://github.com/llmors/cli/releases/latest/download/llmor-linux-x86_64 -o /usr/local/bin/llmor && sudo chmod +x /usr/local/bin/llmor
```

### Updating

Update an installed phar in place with the built-in command:

```bash
sudo llmor self-update            # fetch + verify + replace the latest release
llmor self-update --check         # just report whether an update is available
sudo llmor self-update --force    # reinstall the latest even if already current
```

`self-update` downloads the latest release from GitHub, verifies its SHA-256
checksum, and atomically swaps the binary. Use `sudo` when llmor lives under
`/usr/local/bin` (it tells you if it can't write there).

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

You can keep your credentials global and override just one value per project —
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


> The `.llmor/` directory is git-ignored. Credentials are stored with `0600`
> permissions, as is the cached `session.json`.

## Usage

```bash
./bin/llmor auth:whoami              # show the authenticated user
./bin/llmor auth:whoami --json       # raw JSON
./bin/llmor auth:logout              # forget the cached session (keeps .env)
./bin/llmor conversations:list       # list conversations
./bin/llmor self-update              # update an installed phar to the latest release
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
  A function may declare **multiple** `[copy]` blocks, each with its own `@path`, so
  same-basename files can coexist under different dirs (e.g. several `index.md` under
  `docs/silicon/`, `docs/sandbox/`, `docs/reports/`).

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

## Development

```bash
composer check     # cs (dry-run) + phpstan + phpunit
composer cs-fix    # apply coding-standard fixes
composer test
```

See `make help` for the full list of targets.
