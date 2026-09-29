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
./bin/llmor models:list              # models this vendor can use in an app's [model]
./bin/llmor models:list --type all   # embedding models too
./bin/llmor test <app>               # chat with a declared app (REPL)
./bin/llmor apps:import              # pull an existing app into llmor.scsc
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
  [app_type]    = 'llmor/generic'
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
  [app_type] = 'llmor/generic'
  [name]     = 'Research Bot'
}
```

- `[app_type]` is the **app type** — `llmor/generic`, `llmor/generic_embedded`,
  `llmor/oneshot`, `llmor/autopilot` or `llmor/silicon`. It is fixed when the app is
  created and can never be changed afterwards. (It used to be spelled `[app_key]`, which
  still works but warns on every sync; rename it when you next touch the file.)
- Only `[app_type]` is required. Without `[name]`/`[description]` the app type's own
  name and description are used, and without `[model]` the vendor's default completion
  model is picked.
- `[model]` is a model **name** as shown in the console; the CLI resolves it to an id.
  Names aren't unique, so an ambiguous one is an error rather than a guess. Run
  `./bin/llmor models:list` for the names this vendor accepts — it marks the default
  model with `●` and flags expired ones.
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

Functions reconcile by their key, but an app has no such field: `[app_type]` names the
*type* and the same type can be installed many times, so an app's only identity is its
numeric id. The CLI records those ids in **`llmor.lock`** next to your manifest, keyed
by vendor, and that file is meant to be committed — it is what makes `support_bot` mean
the same app on your machine, your colleague's and in CI. Keying by vendor also lets one
manifest target staging and production without the two fighting over ids.

On a first sync with no lock entry, an app that already exists is **adopted** when
exactly one remote app has the same `[name]` and `[app_type]` — so pointing a manifest at
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

### `apps:import` — start from an app you already have

Most apps are born in the console, not in a manifest, and writing the declaration by
hand means guessing at a parameter bag you can't see — so the first `sync` pushes a pile
of changes you never asked for. `apps:import` goes the other way: it reads the app,
writes the declaration it would have been parsed from, appends that to `llmor.scsc`, and
records the id in `llmor.lock`.

```bash
./bin/llmor apps:import                 # pick from a list of this vendor's apps
./bin/llmor apps:import 17              # or name the id from the console
./bin/llmor apps:import 17 --dry-run    # print the declaration, write nothing
./bin/llmor apps:import 17 --as helpdesk
```

The bar it holds itself to is that **`llmor sync --app <name> --dry-run` reports
`unchanged`** straight afterwards. A few consequences of aiming there:

- **The whole parameter bag comes across**, server-seeded defaults included. It is more
  verbose than something hand-written, but it is what the app actually has — and it is
  why the next sync is a no-op. Trim it afterwards if you like; `[parameters]` are
  overrides, so deleting a line just hands that key back to the console.
- **Long or multi-line values move into files.** A prompt becomes
  `prompts/<name>_prompt.md` with an `@file('./prompts/…')` reference, so it stays
  diffable. `--inline` keeps everything in the manifest instead, and a `: Config` block
  puts them somewhere else than `./prompts`:

  ```scsc
  llmor: Config {
    [prompt_dir] = './resources/prompts'
  }
  ```

  It is the project's own settings block — declared once, anywhere in the manifest, and
  read by `apps:import` alone. The path is relative to the manifest and has to stay
  inside the project; `@file` references you write by hand are never touched by it.
- **The declaration name is derived from `[name]`** ("Support Bot" → `support_bot`) and
  never silently suffixed: if the name is taken you are asked, or told to pass `--as`.
- **Your manifest is appended to, never rewritten.** Comments, alignment and blank lines
  are yours; the only thing normalised is the newline at the end of the file. Before
  anything is written, the prospective manifest is parsed and the declaration read back
  out, so a successful import cannot leave a file `sync` can't read.
- **Console-managed fields are reported, not imported** — `embed_config`,
  `allowed_origins` and `conversation_expire_after` have no manifest syntax and `sync`
  never touches them.
- **A sub-agent pointing at an app your manifest doesn't declare** keeps its numeric id
  (`[app] = 21`), which syncs fine. Import that app too and swap in its name if you want
  the whole graph in one file.

Re-importing an app that is already declared is a no-op, so it's safe in a bootstrap
script. Very occasionally a parameter key can't be written at all — SchemaScript keys
have no quoted form, so `top-k` has no spelling — and it is left out with a warning.
That's safe rather than lossy: an omitted override keeps whatever the server has, which
is exactly the value it was just read from.

## Chat with an app: `llmor test`

`sync` pushes your app; `test` lets you talk to it without opening the console.

```bash
llmor test support_bot                       # a REPL
llmor test support_bot how do I reset it?    # one turn, then exit
llmor test                                   # list the declared apps
```

The answer streams in as the model produces it, and tool calls appear as they run:

```
● Support Bot  #17
  conversation  17-a1b2c3d4
  model         GPT-4
  streaming     live

you › what's the weather in Bern?

bot › Let me look that up.
  ⚙ weather  {"city":"Bern"}
  ✓ weather  {"temp":14,"cond":"rain"}

bot › It's 14°C and raining in Bern right now.
  1.8s · 412 in / 96 out
```

With a message in the arguments it runs one turn and exits, so it works in a script;
`--json` then prints the raw interact response instead. Without one you get a REPL with
`/exit`, `/new`, `/history`, `/token`, `/json` and `/help`.

- The app is resolved **read-only** — an `[id]` pin, then `llmor.lock`, then adoption by
  name + `[app_type]`. `test` never creates an app, so an unsynced declaration is an error
  telling you to run `llmor sync` first. `--app-id 17` skips the manifest entirely.
- `--conversation 17-abc` resumes an existing conversation instead of starting one; the
  token is printed after every turn so you can pick it back up later.
- If the app asks you a question (the ask-user tool), the REPL prompts for each one and
  resumes the turn. In one-shot mode there is nobody to ask, so it prints the questions
  and exits non-zero rather than pretending the turn finished.
- **Ctrl+C** asks the server to stop generating. The runtime checks for that between
  function-call iterations, so it takes effect at the next iteration boundary rather than
  mid-sentence. Press it again to quit.

### How streaming works

Token deltas do not come back on the HTTP response — that call blocks for the whole turn
and then returns the complete message list. They are published to a separate **relay**
service (Socket.IO over WebSocket, at the `relay_url` the conversation record names), so
`test` joins the conversation's channel there and pumps both connections at once.

That means two things worth knowing:

- If the relay is unreachable — a firewall, or local development without it running —
  streaming is skipped with a warning and each turn is printed once it completes. The
  answer is never lost, because it comes from the HTTP response either way. `--no-stream`
  forces that mode, and `--relay-url` overrides the URL the API reports.
- A **sub-agent runs in its own conversation** with its own relay channel, so its
  internal tokens don't stream here. You see the parent's tool call around it — which is
  the right level of detail — and its answer arrives as that call's result.

## Development

```bash
composer check     # cs (dry-run) + phpstan + phpunit
composer cs-fix    # apply coding-standard fixes
composer test
```

See `make help` for the full list of targets.
