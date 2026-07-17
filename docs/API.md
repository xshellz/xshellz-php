# xshellz PHP SDK - API reference

Everything the SDK exposes. All classes live in the `Xshellz` namespace;
exceptions in `Xshellz\Exceptions`. "Throws" lists the SDK's typed errors -
every one of them extends `XshellzException`.

Conventions used below:

- Control-plane calls (create/list/start/stats/...) are HTTPS requests to
  `api.xshellz.com/v1` and can throw `AuthException`, `QuotaException`, or
  `ApiException`.
- Data-plane calls (run/files/spawn/runCode) go over SSH and require the box
  to be `running` - otherwise they throw `SandboxNotRunningException`.

## Sandbox

### `Sandbox::create(?string $name = null, ?string $apiKey = null, ?string $apiUrl = null, float $timeout = 120.0, ?callable $httpHandler = null): Sandbox`

Spawn a new sandbox and return it once it is running (synchronous, typically
a few seconds). Generates an in-memory ed25519 keypair; only the public half
is sent to the server.

- `$name` - optional label; also the handle `getOrCreate()` matches on.
- `$timeout` - HTTP timeout for the create call, seconds.
- `$httpHandler` - Guzzle handler override (used by tests).
- **Throws**: `AuthException` (bad/missing token, scopes, account gates),
  `QuotaException` (concurrent sandbox limit / no entitlement),
  `ApiException` (throttle 429, host capacity 503, ...).

### `Sandbox::getOrCreate(string $name, string|PrivateKey|null $privateKey = null, Keystore|false|null $keystore = null, ?string $apiKey = null, ?string $apiUrl = null, float $timeout = 120.0, ?callable $httpHandler = null): Sandbox`

Get the sandbox named `$name`, creating it if it does not exist. The
"permanent mode" entry point: run it twice and you get the same box twice.

- Not found -> creates the box and, if a keystore is enabled, saves the
  generated private key under the sanitized name.
- Found -> attaches using `$privateKey` if given, else the keystore entry;
  a `stopped` box is `start()`ed first.
- `$keystore`: `null` = default store at `~/.xshellz/keys/`; a `Keystore`
  instance = that store; `false` = no persistence (found boxes then require
  an explicit `$privateKey`).
- **Throws**: `MissingKeyException` (box exists, no key available - the
  message names the expected key path), plus everything `create()` throws.

### `Sandbox::connect(string $uuid, string|PrivateKey $privateKey, ?string $apiKey = null, ?string $apiUrl = null, ?callable $httpHandler = null): Sandbox`

Attach to an existing sandbox by UUID with the private key it was created
with (OpenSSH string or phpseclib key object).

- **Throws**: `XshellzException` (unparsable key),
  `SandboxNotRunningException` (UUID not among the account's sandboxes).

### `Sandbox::list(?string $apiKey = null, ?string $apiUrl = null, ?callable $httpHandler = null): list<SandboxInfo>`

All of the account's active sandboxes.

### `Sandbox::getBoxfile(...): ?string` / `Sandbox::setBoxfile(?string $content, ...): ?string`

Read / save the account-level boxfile: a provisioning template seeded into
`~/xshellz.box` and applied when a NEW box is created (preinstall packages so
destroy+recreate reproduces your environment). Existing boxes are
unaffected. `setBoxfile(null)` (or blank) clears it; max 16 KiB. Both return
the manifest as stored (`null` when none/cleared).

### Read-only properties

`$sbx->uuid`, `$sbx->name`, `$sbx->status` (`running`, `stopped`, ...),
`$sbx->sshHost`, `$sbx->sshPort`, `$sbx->sshCommand` (copy-paste `ssh -p ...`),
`$sbx->privateKey` (phpseclib object), `$sbx->privateKeyOpenSsh` (persistable
string), `$sbx->info` (the full `SandboxInfo`).

### `$sbx->run(string $command, ?string $cwd = null, ?array $env = null, ?float $timeoutSeconds = null, ?callable $onStdout = null, ?callable $onStderr = null): CommandResult`

Run a shell command and wait for it. A non-zero exit code does NOT throw -
inspect `$result->exitCode` / `$result->ok()`. `$onStdout` receives output
chunks live; stderr is buffered by the SSH channel and delivered at the end.

- **Throws**: `SandboxNotRunningException`, `CommandTimeoutException`
  (deadline hit), `XshellzException` (bad env var name, SSH failure).

### `$sbx->runCode(string $language, string $code, ?string $cwd = null, ?array $env = null, ?float $timeoutSeconds = null, ?callable $onStdout = null, ?callable $onStderr = null): CommandResult`

Run a snippet of code: written to a temp file in the box, executed with the
language's interpreter, temp file deleted afterwards regardless of exit
code. Languages: `python` (python3), `node`, `bash`, `ruby`, `php`.
Same result semantics as `run()`.

- **Throws**: `XshellzException` (unsupported language - message lists the
  supported ones), plus everything `run()` throws.

### `$sbx->spawn(string $command, ?string $name = null): JobHandle`

Start a background process (nohup) that outlives the SSH session and your
script. Output goes to `~/.xshellz/jobs/<id>.log` in the box. `$name`
prefixes the generated job id.

- **Throws**: `SandboxNotRunningException`.

### `$sbx->jobs(): list<JobHandle>`

All background jobs (one per log file under `~/.xshellz/jobs/`), each with a
`->running` liveness snapshot taken at listing time. Finished jobs stay
listed until their files are removed.

### Files (SFTP)

- `$sbx->writeFile(string $path, string $data): void`
- `$sbx->readFile(string $path): string` - binary-safe
- `$sbx->upload(string $localPath, string $remotePath): void`
- `$sbx->download(string $remotePath, string $localPath): void`

All throw `SandboxNotRunningException` when the box is not running and
`XshellzException` on SFTP failure.

### Lifecycle & introspection

- `$sbx->start(): void` - resume an idle-stopped box (same files, same key).
  Throws `SandboxNotRunningException` when there is no stopped box to start.
- `$sbx->restart(): void` - reboot a running box (re-runs the entrypoint,
  `/home` preserved). Throws `SandboxNotRunningException` on 404.
- `$sbx->kill(): void` - destroy the box. Idempotent; a 404 is swallowed;
  a no-op after `detach()`.
- `$sbx->detach(): void` - keep the box alive: `kill()` becomes a no-op.
- `$sbx->refresh(): SandboxInfo` - re-fetch control-plane state.
- `$sbx->close(): void` - close the SSH connection, keep the box.
- `$sbx->stats(): array` - live usage, wire fields as-is: `mem_used_mb`,
  `mem_limit_mb`, `mem_allowed_mb`, `cpu_percent`, `cpu_allowed_vcpus`,
  `cpu_throttled_periods`, `pids_current`, `pids_allowed`, `disk_used_mb`,
  `disk_allowed_mb`, `net_rx_mb`, `net_tx_mb`, `blk_read_mb`, `blk_write_mb`.
- `$sbx->procs(): array` - `procs` (list of `{pid, comm, cpu, mem}`),
  `sessions` (active SSH sessions), `agents`, `disk_used_mb`,
  `disk_allowed_mb`.
- `$sbx->terminalUrl(): string` - freshly minted signed web-terminal URL
  (root shell in the browser). The token expires after ~1 hour; mint fresh,
  don't store.

## JobHandle

Returned by `spawn()` and `jobs()`.

- Properties: `->id` (string), `->pid` (?int), `->logPath` (string, remote),
  `->running` (?bool - snapshot from `jobs()`, `null` on fresh spawns).
- `->isRunning(): bool` - live `kill -0` probe.
- `->logs(int $tailLines = 100): string` - tail of the job's log (empty
  string if no log yet).
- `->stop(int $graceSeconds = 5): void` - SIGTERM, then SIGKILL if still
  alive after the grace window. Safe on already-dead jobs.

All three run over SSH and throw `SandboxNotRunningException` when the box
is not running.

## Keystore

Local plaintext key storage for `getOrCreate()`; directory 0700, key files
0600. Anyone with a key file has root SSH to that box - delete the file to
revoke.

- `new Keystore(?string $directory = null)` - default `$HOME/.xshellz/keys`;
  throws `XshellzException` when `HOME` is unset and no directory is given.
- `->directory(): string`, `->path(string $name): string`
- `->save(string $name, string $privateKeyOpenSsh): string` - returns the
  written path; throws `XshellzException` on write failure.
- `->load(string $name): ?string` - `null` when absent.
- `->delete(string $name): void` - no-op when absent.
- `Keystore::sanitize(string $name): string` - filename-safe name component.

## CommandResult

`->stdout` (string), `->stderr` (string), `->exitCode` (int),
`->ok(): bool` (`exitCode === 0`).

## SandboxInfo

Control-plane state (readonly): `uuid`, `name`, `status`, `sshCommand`,
`sshHost`, `sshPort`, `webTerminalReady`, `alwaysOn`, `trialHoursRemaining`,
`spawnedAt`, `createdAt`, `isolation`, `gvisor`.

## KeyPair

- `KeyPair::generate(string $comment = 'xshellz-sdk'): KeyPair` - fresh
  in-memory ed25519 keypair (`->privateKey`, `->privateKeyOpenSsh`,
  `->publicKeyLine`).
- `KeyPair::loadPrivateKey(string|PrivateKey $privateKey): PrivateKey` -
  parse an OpenSSH/PEM private key; throws `XshellzException` on garbage or
  public-key material.

## Config

- `Config::resolveApiKey(?string $apiKey = null): string` - argument >
  `XSHELLZ_API_KEY` env; throws `AuthException` when neither is set.
- `Config::resolveApiUrl(?string $apiUrl = null): string` - argument >
  `XSHELLZ_API_URL` env > `https://api.xshellz.com/v1`.

## Exceptions

| Exception | Meaning |
|---|---|
| `XshellzException` | Base class for every SDK error |
| `AuthException` | 401/403: token missing/invalid, scopes, verification gates |
| `QuotaException` | Plan sandbox limit reached or no sandbox entitlement |
| `MissingKeyException` | `getOrCreate()` found the box but has no key for it |
| `SandboxNotRunningException` | Operation requires a `running` box |
| `CommandTimeoutException` | `run(timeoutSeconds: ...)` deadline exceeded |
| `ApiException` | Any other 4xx/5xx; carries `->statusCode` and `->body` |
