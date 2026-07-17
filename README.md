# xshellz

[![CI](https://github.com/xshellz/xshellz-php/actions/workflows/ci.yml/badge.svg)](https://github.com/xshellz/xshellz-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/xshellz/xshellz)](https://packagist.org/packages/xshellz/xshellz)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

The official PHP SDK for [xShellz](https://xshellz.com) sandboxes - spawn a
real Linux box from your code, run commands in it, and throw it away (or keep
it forever).

**What is a sandbox?** A sandbox is a small, disposable Linux computer in the
cloud that your program fully controls: it has a root shell, a package
manager, network access, and its own files, isolated from everyone else by
[gVisor](https://gvisor.dev). It is the safe place to run things you would
not run on your own machine - installs, builds, untrusted or AI-generated
code.

## Quickstart

**1. Install the SDK** (PHP >= 8.2; pure PHP, no `ext-ssh2` needed):

```bash
composer require xshellz/xshellz:^0.2
```

**2. Get an API key.** Sign up at [app.xshellz.com](https://app.xshellz.com)
and create a personal access token with `read` and `write` scopes under
Settings -> API tokens (or via the API: `POST /v1/auth/tokens`). Then:

```bash
export XSHELLZ_API_KEY="your-token"
```

**3. Hello world:**

```php
use Xshellz\Sandbox;

$sbx = Sandbox::create();
try {
    echo $sbx->run('echo hello from $(hostname)')->stdout;
} finally {
    $sbx->kill(); // the box is destroyed
}
```

`Sandbox::create()` returns once the box is running - typically a few
seconds. PHP has no context manager, so the idiomatic pattern is try/finally
with `kill()` as teardown; call `detach()` inside the block if you want to
keep the box.

## Recipes

### Run a command

```php
$r = $sbx->run('apt-get update && apt-get install -y jq', timeoutSeconds: 300);
echo $r->exitCode, $r->stdout, $r->stderr;

// A non-zero exit code does NOT throw - it's data:
$r = $sbx->run('false');
assert($r->ok() === false);

// cwd, env, and live output streaming:
$sbx->run('make test', cwd: '/srv/app', env: ['CI' => '1']);
$sbx->run('npm run build', onStdout: fn (string $chunk) => print($chunk));
```

### A permanent named box that survives restarts

`Sandbox::getOrCreate()` gives you a box by name: it creates it the first
time and re-attaches to the same box (same files, same key) every time after
- even from a different process, days later. The generated private key is
saved to a local keystore (`~/.xshellz/keys/`, one 0600 file per box name)
so nothing needs to be hand-carried between runs.

```php
$sbx = Sandbox::getOrCreate('my-forever-box');
$sbx->run('echo persisted >> /root/notes.txt');
$sbx->detach(); // keep it alive
// ...tomorrow, in another script:
$sbx = Sandbox::getOrCreate('my-forever-box'); // same box, same notes.txt
```

A stopped box (free boxes idle-stop after ~30 minutes) is started
automatically. Security note: the keystore holds plaintext private keys on
disk (0600, your user only) - anyone with the file has root SSH to that box;
delete the file to revoke. Pass `keystore: false` to disable persistence, or
`privateKey:` to supply a key yourself.

### Background jobs (spawn)

`spawn()` starts a process that keeps running after your script moves on -
servers, long builds, watchers:

```php
$job = $sbx->spawn('python3 -m http.server 8000', name: 'web');

$job->pid;              // process id inside the box
$job->isRunning();      // true while alive
echo $job->logs(50);    // last 50 lines of its output
$job->stop();           // SIGTERM, then SIGKILL after a grace period

foreach ($sbx->jobs() as $j) {       // every job log on the box
    echo $j->id, ' running=', var_export($j->running, true), PHP_EOL;
}
```

### Run AI-generated code safely (runCode)

Hand `runCode()` a string of code - no shell-quoting, no temp-file
bookkeeping. It writes the code into the box, runs the right interpreter
(`python` -> python3, `node`, `bash`, `ruby`, `php`), and cleans up:

```php
$r = $sbx->runCode('python', <<<'PY'
    import json, urllib.request
    print(json.dumps({"n": 6 * 7}))
    PY);
echo $r->stdout; // {"n": 42}
```

Anything the code does stays inside the sandbox - that is the point.

### Files up and down

```php
$sbx->writeFile('/tmp/config.json', '{"debug": true}');
$data = $sbx->readFile('/tmp/config.json'); // string (binary-safe)

$sbx->upload('local.txt', '/tmp/remote.txt');
$sbx->download('/tmp/remote.txt', 'out.txt');
```

### Check resource usage

```php
$stats = $sbx->stats();  // mem_used_mb, cpu_percent, disk_used_mb, pids_current, ...
$procs = $sbx->procs();  // top processes + active SSH session count
$sbx->restart();         // reboot the box; /home is preserved
```

### Open a web terminal

```php
echo $sbx->terminalUrl(); // open in any browser for a root terminal
```

The URL embeds a signed token that expires after about an hour - mint a
fresh one each time instead of storing it.

### Provision every new box the same way (boxfile)

The account-level boxfile is a template applied whenever a NEW box is
created - use it to preinstall your dependencies so destroy+recreate always
reproduces your environment:

```php
Xshellz\Sandbox::setBoxfile("jq\nripgrep\npython3-pip");
echo Xshellz\Sandbox::getBoxfile();
```

## API reference

Every public method, its parameters, return shapes, and errors:
**[docs/API.md](docs/API.md)**.

## Configuration

| Environment variable | Meaning | Default |
|---|---|---|
| `XSHELLZ_API_KEY` | Personal access token (`read` + `write` scopes) | - (required) |
| `XSHELLZ_API_URL` | Control-plane base URL | `https://api.xshellz.com/v1` |

Precedence: explicit argument > environment variable > default.

## Error types

All SDK errors extend `Xshellz\Exceptions\XshellzException`, so one catch
gets everything.

| Exception | When |
|---|---|
| `AuthException` | 401/403: bad or missing token, scopes, account gates |
| `QuotaException` | Plan sandbox limit reached / plan has no sandbox entitlement |
| `MissingKeyException` | `getOrCreate()` found the box but no private key for it |
| `SandboxNotRunningException` | The operation needs a `running` box |
| `CommandTimeoutException` | `run(timeoutSeconds: ...)` exceeded |
| `ApiException` | Any other 4xx/5xx (carries `->statusCode` and `->body`) |

## How it works

- **Control plane**: HTTPS to `api.xshellz.com/v1` (create / list / start /
  destroy / stats), authenticated by your token.
- **Data plane**: SSH directly to the box as `root` (via
  [phpseclib](https://phpseclib.com)). `Sandbox::create()` generates an
  in-memory ed25519 keypair per sandbox; the private key never leaves your
  process and the server never sees it - only the public half is installed
  in the box's `authorized_keys`.
- **Host keys are auto-accepted.** Sandbox host keys are generated at spawn
  time, so there is no out-of-band fingerprint to pin. If your threat model
  requires host-key verification, connect manually with your own SSH tooling
  using `$sbx->sshCommand`.

## v0 limits

- **Free tier: 1 concurrent sandbox.** A second `Sandbox::create()` throws
  `QuotaException` while one exists - use `getOrCreate()`/`connect()` to
  attach to the existing box, or `kill()` it first. Paid plans raise the
  limit.
- **Free boxes idle-stop after ~30 minutes.** The box (its files and your
  key) is preserved; `start()` - or `getOrCreate()` - resumes it.
- Sandbox creation is throttled to 10 requests/minute per account.

## Local development (Docker)

No local PHP needed - the test suite (with its >= 80% coverage gate) runs in
a container:

```bash
docker compose run --rm test
```

This builds a PHP 8.3 CLI image with the pcov coverage driver, runs
`composer install`, and executes the Pest suite against the repo mounted at
`/work`. The composer cache is kept in a named volume between runs. The
container runs as uid/gid 1000 by default so it never leaves root-owned
files in your checkout; override with `DOCKER_UID`/`DOCKER_GID` if your user
differs.

Without Docker:

```bash
composer install
composer test              # plain run
composer test:coverage     # with the >= 80% coverage gate (needs pcov/xdebug)
```

## License

MIT
