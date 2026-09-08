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
- A `[copy]` source may be a **wildcard pattern**, so a growing directory does not mean a
  hand-maintained list that goes stale. `*` and `?` match within one path segment, `**`
  matches across them, and dot-files are skipped. A pattern keeps each match's path
  *relative to its own fixed prefix* rather than flattening to a basename, so one recursive
  pattern mirrors a whole tree:

  ```scsc
  @path('docs/')
  [copy]        = { './resources/silicon/docs/*' }        # -> docs/pjas.md, docs/symbols.json, ...

  @path('docs/book/')
  [copy]        = { './resources/books/silicon/**/*.md' } # -> docs/book/index.md,
                                                          #    docs/book/data/dql.md,
                                                          #    docs/book/data/index.md, ...
  ```

  `**/` also matches zero directories, so the pattern above covers the tree's own root
  files. A pattern that matches **nothing** is an error rather than a silent no-op — it is
  indistinguishable from a typo.

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

## Apps: declarative sync

The same manifest can declare **apps** — the chat agents that talk to your users — so a
project's prompt, model, installed functions and sub-agent wiring live in the repo
instead of in the web console:

```scsc
support_bot: App {
  [app_key]     = 'llmor/generic'
  [name]        = 'Support Bot'
  [description] = 'Answers customer questions.'
  [model]       = 'GPT-4'

  [parameters] = {
    @file('./prompts/support.md')       // load a long value from a file
    prompt = ''

    temperature = 0.2
    enable_function_calling = true
  }

  [functions] = {                       // functions this app can call
    [silicon_greeter] = {}
    [weather] = { units = 'metric' }    // ...with per-app config
  }

  [subagents] = {                       // delegate to another app
    [research] = {
      [app]              = research_bot
      [expose_as_tool]   = true
      [tool_name]        = 'deep_research'
      [tool_description] = 'Ask the research assistant a question.'
    }
  }
}

research_bot: App {
  [app_key] = 'llmor/generic'
  [name]    = 'Research Bot'
}
```

- `[app_key]` is the **app type** — `llmor/generic`, `llmor/generic_embedded`,
  `llmor/oneshot`, `llmor/autopilot` or `llmor/silicon`. It is fixed when the app is
  created and can never be changed afterwards.
- Only `[app_key]` is required. Without `[name]`/`[description]` the app type's own
  name and description are used, and without `[model]` the vendor's default completion
  model is picked.
- `[model]` is a model **name** as shown in the console; the CLI resolves it to an id.
  Names aren't unique, so an ambiguous one is an error rather than a guess.
- **`@file('./path')`** makes any value come from a file next to the manifest. Use it
  for system prompts, Lua `code`, JSON schemas and CSS — anything you would rather edit
  and diff on its own.
- `[functions]` lists functions the app installs, by key. They may be declared in the
  same manifest or exist only remotely. Declaring the block means the manifest **owns**
  the set: `[functions] = {}` unlinks everything, while leaving the block out entirely
  leaves whatever the console configured alone.
- With no config to pass, `[functions] = { silicon_greeter, weather }` says the same
  thing more briefly. One caveat from SchemaScript itself: a `{ … }` block must pick a
  single shape — a list of bare names, *or* `[name] = { … }` entries — because mixing
  the two makes the parser read the block as a list and stop at the first bracket key.
- `[subagents]` binds an alias to another app. `[app]` names another declaration (or a
  numeric app id for an app outside this manifest); `[subagents] = { research }` is
  shorthand for "same-named app, defaults". Order in the file doesn't matter — targets
  are wired once every app exists.

### `llmor.lock` — commit it

Functions reconcile by their key, but an app has no such field: `app_key` names the
*type* and the same type can be installed many times, so an app's only identity is its
numeric id. The CLI records those ids in **`llmor.lock`** next to your manifest, keyed
by vendor, and that file is meant to be committed — it is what makes `support_bot` mean
the same app on your machine, your colleague's and in CI. Keying by vendor also lets one
manifest target staging and production without the two fighting over ids.

On a first sync with no lock entry, an app that already exists is **adopted** when
exactly one remote app has the same `[name]` and `[app_key]` — so pointing a manifest at
apps you built by hand doesn't duplicate them. If several match, the sync stops and asks
you to pin one with `[id] = <id>`.

### Two things worth knowing

- **`[parameters]` are overrides, merged over what's already there.** Declare only what
  you care about; server defaults and anything set in the console survive. The flip side
  is that *removing* a key from the manifest does not reset it. Nested maps merge, while
  lists (like `examples`) replace wholesale.
- **Sub-agent fields are owned outright.** The API requires all of them on every write,
  so dropping `[tool_description]` really does clear it remotely.

A sub-agent that exists remotely but is no longer declared is reported, never deleted —
as with orphaned function files. Apps themselves are never deleted either (the API
reserves that for super-admins), so review a `--dry-run` before a first sync.

```bash
./bin/llmor sync                        # functions and apps together
./bin/llmor sync --app support_bot      # just one app
./bin/llmor sync --dry-run              # show what would change, write nothing at all
./bin/llmor sync --json                 # machine-readable result
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
