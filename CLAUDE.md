# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` (each `-`-separated word upper-cased) unless `--namespace=`
overrides it. Existing exceptions the guess gets wrong: `bignum` → `BigNum`,
`dataloader` → `DataLoader`, `dotenv` → `Env`, `graphql` → `GraphQL`, `oauth` → `OAuth`,
`opcache` → `OPCache`, `swagger-ui` → `SwaggerUI`, `webauthn` → `WebAuthn` and
`websocket` → `WebSocket`; `websocket-client` → `WebsocketClient`, `websocket-tls` → `WebsocketTls`,
`webauthn-metadata` → `WebauthnMetadata` and `metrics-statsd` → `MetricsStatsd` are
intentional lower-case-word namespaces, and `testing-application` shares `EzPhp\Testing\`
with `testing`).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4` **and** the shared
`autoload-dev` `Tests\` directory list), `phpstan.neon`, `phpunit.xml` (test suite
**and** coverage source), and `packages.sh` (alphabetical position) — in both
generated and `--repo` mode.

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — claim the "next free" row by
  editing the table in `CODING_GUIDELINES.md` (never in a `CLAUDE.md` copy) and run
  `composer guidelines:sync` in the same change. Editing it drifts every `CLAUDE.md`
  until the sync runs, which is why the generator only reminds you instead of doing
  it. Skipping the edit leaves "next free" stale, so the next module collides.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. Mailpit is the one other service with published host ports: SMTP `1025` and web UI `8025`. `ez-php/mail` maps them through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above, documented in `modules/mail/.env.example`); the root project and the `ez-php/` template each run their own Mailpit on the same defaults (`MAIL_PORT`/`MAIL_WEB_PORT`), so **these three stacks cannot run at the same time** without overriding those variables. It isn't a table column because no module beyond those three runs Mailpit — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/websocket-tls

TLS/WSS termination for `ez-php/websocket` — a `tcp://` listener carrying the certificate, and a Fiber event loop that hands negotiated connections to the plain WebSocket server's `Connection`/`HandlerInterface`.

---

## Source Structure

```
src/
├── TlsServerException.php   — thrown when the TLS listener cannot be created
├── CryptoNegotiator.php     — drives stream_socket_enable_crypto() to completion within a timeout
└── TlsServer.php            — tcp:// listener with TLS context + Fiber event loop, mirroring ez-php/websocket's Server

tests/
├── TestCase.php              — Base PHPUnit test case
├── TestCertificate.php       — generates a throwaway self-signed cert/key pair for TLS tests
├── CryptoNegotiatorTest.php  — real TLS handshakes over loopback TCP sockets
├── TlsServerTest.php         — constructor/accessors, run() failure modes
├── TlsServerLifecycleTest.php — in-process: handleConnection over socket pairs, certificate/key validation, sslOptions
├── TlsServerEndToEndTest.php — real TlsServer in a child process + raw WSS client: upgrade, echo, ping/pong, close, invalid upgrade, non-TLS client, concurrency
└── Support/tls-echo-server.php — the child process: TlsServer + echo handler that logs lifecycle callbacks to a file
```

---

## Key Classes and Responsibilities

### TlsServerException (`src/TlsServerException.php`)

Single exception type for this module. Thrown by `TlsServer::run()` when the
listener cannot be created (bad certificate path, port already bound,
etc.).

---

### CryptoNegotiator (`src/CryptoNegotiator.php`)

Wraps `stream_socket_enable_crypto()`, which on a non-blocking stream returns
`0` (not `false`) while it still needs more bytes from the peer. `negotiate()`
retries the call, waiting for the socket to become readable between attempts
(via `stream_select()`, capped at 50ms per wait), until it settles on
`true`/`false` or a caller-supplied timeout elapses. Single responsibility, so
it is unit-testable in isolation with a real TLS handshake over loopback TCP
sockets (`CryptoNegotiatorTest`) rather than only through the full `TlsServer`.

---

### TlsServer (`src/TlsServer.php`)

Structurally mirrors `ez-php/websocket`'s `Server`: same Fiber-per-connection
event loop, same `stream_select()`-driven accept/resume cycle, same
`handleConnection()` RFC 6455 frame dispatch. The differences are all in how a
connection is admitted:

1. `run()` builds a `tcp://` listener via `stream_socket_server()` with an SSL
   context carrying `local_cert`/`local_pk`/`passphrase`. It must **not** be an
   `ssl://` listener: PHP then completes the TLS handshake inside
   `stream_socket_accept()` (blocking the loop per slow client), and the
   negotiator's `stream_socket_enable_crypto()` on the already-encrypted socket
   fails — which dropped every connection until `TlsServerEndToEndTest` caught it.
2. `acceptConnection()` sets the accepted socket non-blocking, then calls
   `CryptoNegotiator::negotiate()` before doing anything else. A connection
   whose TLS handshake fails or times out is closed immediately — it never
   reaches a `Connection` or `HandlerInterface`.
3. Once TLS is established, the (now plaintext) stream is wrapped in
   `EzPhp\WebSocket\Connection` exactly as `Server` would, so the RFC 6455
   handshake, frame codec, and `HandlerInterface` contract are unchanged for
   application code — swapping `Server` for `TlsServer` is a drop-in change.

---

## Design Decisions and Constraints

- **Composition over inheritance, forced by `ez-php/websocket`'s own rules.**
  `Server` is `final` and hardcodes `tcp://` in `run()`, so it cannot be
  extended or configured for TLS. `Connection` is not — its constructor takes
  any stream resource plus an ID string — so `TlsServer` reuses `Connection`
  directly instead of duplicating the RFC 6455 handshake/frame logic, but had
  to reimplement `Server`'s accept/event loop itself. Depends on
  `ez-php/websocket` as an ordinary sibling dependency; no changes were made
  to that package.
- **TLS handshake negotiation is a bounded blocking poll, not Fiber-suspended.**
  `CryptoNegotiator::negotiate()` retries `stream_socket_enable_crypto()` in a
  short loop (`stream_select()` capped at 50ms per wait, total bounded by
  `TlsServer::HANDSHAKE_TIMEOUT` = 5s) before a connection's Fiber is even
  created. Fully async TLS negotiation would require extending the same
  suspend/resume machinery `Server` uses for the WebSocket handshake to the
  crypto layer as well — disproportionate complexity for what
  `EZ_PHP_IDEAS.md` already flags as a low-priority extension ("a reverse
  proxy already covers the common deployment"). The bounded poll only delays
  the accept loop while a handshake is actually in flight for one connection
  at a time; it does not block already-established connections, whose Fibers
  are unaffected.
- **`allow_self_signed: true`, `verify_peer: false` on the server-side SSL
  context.** This is a server terminating client connections, not a client
  verifying a server — there is no peer certificate to validate here (browsers
  don't present client certs for `wss://`). These options only affect what the
  server itself would reject if it initiated connections, which it doesn't.
- **No certificate hot-reload / SNI / multiple certificates.** One
  `certFile`/`keyFile` pair per `TlsServer` instance, read once by the
  `stream_context` at `run()` time. Multi-domain or rotating-certificate setups
  are exactly the case a reverse proxy handles better; out of scope here.

---

## Testing Approach

- Test classes live in the shared `Tests\` namespace but must be uniquely named
  across the whole monorepo — the root `phpunit.xml` loads every package in one
  process, so a duplicate name is a fatal error, not a test failure. Prefix with
  `WebsocketTls` when the obvious name is already taken.
- No external infrastructure required, but unlike `ez-php/websocket`'s own
  suite, `CryptoNegotiatorTest` needs a **real TLS handshake** to exercise
  `stream_socket_enable_crypto()` meaningfully — `stream_socket_pair()` (UNIX
  domain socket pairs) does **not** support SSL/crypto in PHP's stream layer
  (confirmed empirically: `stream_socket_enable_crypto()` raises "This stream
  does not support SSL/crypto" on such pairs), so tests bind real loopback TCP
  sockets (`tcp://127.0.0.1:0`, letting the OS assign a free port) instead.
- `TestCertificate` generates a throwaway self-signed certificate/key pair via
  `openssl_pkey_new()`/`openssl_csr_new()`/`openssl_csr_sign()` into temp files
  for each test, cleaned up in a `finally` block.
- `CryptoNegotiatorTest` pumps both sides of a real handshake (server via the
  class under test, client via a direct `stream_socket_enable_crypto()` call)
  in a loop until both complete, and separately verifies `negotiate()` returns
  `false` — rather than hanging — when the peer never speaks TLS at all.
- `TlsServerTest` mirrors `ez-php/websocket`'s `ServerTest` boundary: only
  constructor/accessors and `run()` failure modes (bad certificate path, port
  already in use) are covered here, in-process.
- `TlsServerLifecycleTest` covers, in-process and visible to coverage, what happens to a connection *after* TLS (`handleConnection()` over a socket pair, same cases as the plain server), the certificate/private-key validation and `sslOptions()`. `acceptConnection()` and the loop need a live TLS peer and stay with the end-to-end suite (TlsServer ≈ 60 % lines in-process).
- `TlsServerEndToEndTest` runs a real `TlsServer` in a child process
  (`php tests/Support/tls-echo-server.php`) and talks to it with a raw WSS
  client over loopback: TLS handshake, WebSocket upgrade, text echo, ping/pong,
  close, an invalid upgrade request (`HandshakeException` reaches `onError`),
  a client that never speaks TLS (dropped, server keeps serving), and two
  concurrent clients. The child's lines are invisible to the parent's coverage
  driver, so this suite guards behaviour while `TlsServerTest` keeps the
  in-process coverage numbers. It caught the `ssl://`-listener bug described
  in the description of `TlsServer` above — every connection was being dropped.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Plain-TCP WebSocket handshake, frame codec, pub/sub | `ez-php/websocket` (this module only changes how the socket is created and terminates TLS) |
| SNI / multiple certificates / certificate rotation | Reverse proxy (nginx, Caddy) in front of the WebSocket process |
| Client-side WSS connections (connecting *to* a WSS server) | Application layer, or a future WebSocket client package — this module is server-only |
| SSE / server-sent events | `ez-php/broadcast` |
| Authentication / authorization | Application handler, same as `ez-php/websocket` (`onOpen()`: inspect `header('origin')`/`header('cookie')`) |
