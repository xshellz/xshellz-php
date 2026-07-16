# xshellz

Official PHP SDK for [xShellz](https://xshellz.com) sandboxes: throwaway,
gVisor-isolated Linux boxes you can spawn and run commands in from your own
program - in three lines.

```bash
composer require xshellz/xshellz
```

```php
use Xshellz\Sandbox;

$sbx = Sandbox::create();
try {
    $result = $sbx->run("php -r 'echo 6*7;'");
    echo $result->stdout; // 42
} finally {
    $sbx->kill(); // the box is destroyed
}
```

Each sandbox is a real Linux box (root shell, package manager, network) running
under [gVisor](https://gvisor.dev) kernel isolation. Spawning is synchronous -
`Sandbox::create()` returns once the box is running, typically in a few seconds.

Requires PHP >= 8.2. Pure PHP: SSH/SFTP via
[phpseclib](https://phpseclib.com), no `ext-ssh2` needed.

## Authentication

The SDK authenticates with an xShellz personal access token (PAT) carrying the
`read` and `write` scopes:

1. Create a token from your [xShellz dashboard](https://app.xshellz.com)
   (Settings -> API tokens), or via the API: `POST /v1/auth/tokens`.
2. Export it:

```bash
export XSHELLZ_API_KEY="your-token"
```

or pass it explicitly: `Sandbox::create(apiKey: 'your-token')`.

Config precedence: explicit argument > `XSHELLZ_API_KEY` / `XSHELLZ_API_URL`
environment variables > default (`https://api.xshellz.com/v1`).

To target a staging or self-hosted control plane:

```bash
export XSHELLZ_API_URL="https://api.staging.example.com/v1"
```

## Usage

### Run commands

```php
$sbx = Sandbox::create(name: 'build-box');
try {
    $r = $sbx->run('apt-get update && apt-get install -y jq', timeoutSeconds: 300);
    echo $r->exitCode, $r->stdout, $r->stderr;

    // A non-zero exit code does NOT throw - it's data:
    $r = $sbx->run('false');
    assert($r->exitCode === 1);
    assert($r->ok() === false);

    // cwd and env:
    $sbx->run('make test', cwd: '/srv/app', env: ['CI' => '1']);

    // Stream long-running output as it arrives:
    $sbx->run(
        'npm run build',
        onStdout: fn (string $chunk) => print($chunk),
        onStderr: fn (string $chunk) => fwrite(STDERR, $chunk),
    );
} finally {
    $sbx->kill();
}
```

### Files (SFTP)

```php
$sbx->writeFile('/tmp/config.json', '{"debug": true}');
$data = $sbx->readFile('/tmp/config.json'); // string (binary-safe)

$sbx->upload('local.txt', '/tmp/remote.txt');
$sbx->download('/tmp/remote.txt', 'out.txt');
```

### Lifecycle

```php
$sbx->uuid;              // sandbox id
$sbx->sshHost;           // e.g. "shellus1.xshellz.com"
$sbx->sshPort;           // e.g. 42001
$sbx->sshCommand;        // ready-to-copy "ssh -p 42001 root@..."
$sbx->status;            // "running", "stopped", ...
$sbx->privateKeyOpenSsh; // persist to reconnect later

$sbx->detach();          // keep the box alive: kill() becomes a no-op
$sbx->kill();            // destroy the box (idempotent; 404 is swallowed)
$sbx->start();           // resume an idle-stopped box

// Re-attach later (persist $sbx->privateKeyOpenSsh + $sbx->uuid for this):
$sbx = Sandbox::connect($uuid, privateKey: $savedPrivateKey);

// Enumerate your sandboxes:
foreach (Sandbox::list() as $info) {
    echo $info->uuid, ' ', $info->status, PHP_EOL;
}
```

PHP has no context manager, so the idiomatic pattern is try/finally with
`kill()` as the teardown; call `detach()` inside the block to keep the box
(after `detach()`, `kill()` is a no-op).

### Typed exceptions

```php
use Xshellz\Exceptions\AuthException;
use Xshellz\Exceptions\QuotaException;
use Xshellz\Sandbox;

try {
    $sbx = Sandbox::create();
} catch (QuotaException) {
    // plan limit reached - attach to the existing box instead
    $existing = Sandbox::list()[0];
    $sbx = Sandbox::connect($existing->uuid, privateKey: $savedKey);
} catch (AuthException $e) {
    echo $e->getMessage(); // missing/invalid token, scope, or account verification
}
```

- `XshellzException` - base class for everything the SDK throws
- `AuthException` - 401/403: bad or missing token, scopes, account gates
- `QuotaException` - plan sandbox limit reached / plan has no sandbox entitlement
- `SandboxNotRunningException` - operation needs a `running` box
- `CommandTimeoutException` - `run(timeoutSeconds: ...)` exceeded
- `ApiException` - any other 4xx/5xx (carries `->statusCode` and `->body`)

## How it works

- **Control plane**: HTTPS to `api.xshellz.com/v1` (create / list / start /
  destroy), authenticated by your PAT.
- **Data plane**: SSH directly to the box as `root`. `Sandbox::create()`
  generates an in-memory ed25519 keypair per sandbox; the private key never
  leaves your process and the server never sees it - only the public half is
  installed in the box's `authorized_keys`.
- **Host keys are auto-accepted.** Sandbox host keys are generated at spawn
  time, so there is no out-of-band fingerprint to pin. If your threat model
  requires host-key verification, connect manually with your own SSH tooling
  using `$sbx->sshCommand`.
- **Streaming**: stdout is streamed to `onStdout` chunk-by-chunk as it
  arrives; stderr travels on the SSH extended-data channel, which phpseclib
  buffers separately, so `onStderr` receives it when the command finishes
  (the full stderr is always available on the result).

## v0 limits

- **Free tier: 1 concurrent sandbox.** A second `Sandbox::create()` throws
  `QuotaException` while one exists - use `Sandbox::list()` +
  `Sandbox::connect()` to attach to the existing box, or `kill()` it first.
  Paid plans raise the limit.
- **Free boxes idle-stop after ~30 minutes.** The box (its `/home` and your
  key) is preserved; call `$sbx->start()` to resume it.
- Sandbox creation is throttled to 10 requests/minute per account.

## Local development (Docker)

No local PHP needed - the test suite runs in a container:

```bash
docker compose run --rm test
```

This builds a PHP 8.3 CLI image, runs `composer install`, and executes the
Pest test suite against the repo mounted at `/work`. The composer cache is
kept in a named volume between runs. The container runs as uid/gid 1000 by
default so it never leaves root-owned files in your checkout; override with
`DOCKER_UID`/`DOCKER_GID` if your user differs.

Without Docker:

```bash
composer install
composer test
```

## License

MIT
