<?php

declare(strict_types=1);

namespace Xshellz;

use OutOfBoundsException;
use phpseclib3\Crypt\Common\PrivateKey;
use Xshellz\Exceptions\ApiException;
use Xshellz\Exceptions\MissingKeyException;
use Xshellz\Exceptions\SandboxNotRunningException;
use Xshellz\Exceptions\XshellzException;
use Xshellz\Ssh\PhpseclibTransport;
use Xshellz\Ssh\ShellCommand;
use Xshellz\Ssh\SshTransportInterface;

/**
 * A remote sandbox: control plane over HTTPS, data plane over SSH.
 *
 * Create one with Sandbox::create(), get-or-create a persistent named one
 * with Sandbox::getOrCreate(), attach to an existing one with
 * Sandbox::connect(), or enumerate them with Sandbox::list().
 *
 * PHP has no context manager, so pair create() with a try/finally:
 *
 *     $sbx = Sandbox::create(name: 'demo');
 *     try {
 *         $result = $sbx->run('echo hello');
 *     } finally {
 *         $sbx->kill(); // or $sbx->detach() beforehand to keep the box
 *     }
 *
 * @property-read SandboxInfo $info Last-known control-plane state.
 * @property-read string $uuid
 * @property-read string $name
 * @property-read string $status
 * @property-read string|null $sshHost
 * @property-read int|null $sshPort
 * @property-read string|null $sshCommand
 * @property-read PrivateKey|null $privateKey In-memory private key authenticating this sandbox's SSH.
 * @property-read string|null $privateKeyOpenSsh OpenSSH serialization of the private key (persist it to reconnect later via connect()).
 */
final class Sandbox
{
    private const STATUS_RUNNING = 'running';

    private const STATUS_STOPPED = 'stopped';

    /**
     * Interpreter + file extension per runCode() language.
     */
    private const INTERPRETERS = [
        'python' => ['python3', 'py'],
        'node' => ['node', 'js'],
        'bash' => ['bash', 'sh'],
        'ruby' => ['ruby', 'rb'],
        'php' => ['php', 'php'],
    ];

    private bool $detached = false;

    private bool $killed = false;

    /**
     * @internal Use Sandbox::create(), Sandbox::connect(), or Sandbox::list().
     */
    public function __construct(
        private SandboxInfo $info,
        private readonly ApiClient $api,
        private readonly ?PrivateKey $privateKey = null,
        private readonly ?string $privateKeyOpenSsh = null,
        private ?SshTransportInterface $transport = null,
    ) {
    }

    // ------------------------------------------------------------------ //
    // Constructors (control plane)
    // ------------------------------------------------------------------ //

    /**
     * Spawn a new sandbox and return it once it is RUNNING.
     *
     * An in-memory ed25519 keypair is generated for the box; the private key
     * never leaves this process and the server never sees it. Spawning is
     * synchronous - the box is reachable when this returns.
     *
     * @param callable|null $httpHandler Guzzle handler override (for tests).
     *
     * @throws Exceptions\AuthException Missing/invalid API key, insufficient
     *     scope, or an account gate (verification, preview).
     * @throws Exceptions\QuotaException The plan's concurrent sandbox limit is
     *     reached or the plan has no sandbox entitlement.
     * @throws ApiException Other API failures (throttle 429, host capacity 503, ...).
     */
    public static function create(
        ?string $name = null,
        ?string $apiKey = null,
        ?string $apiUrl = null,
        float $timeout = 120.0,
        ?callable $httpHandler = null,
    ): self {
        $keyPair = KeyPair::generate();
        $api = new ApiClient($apiKey, $apiUrl, $timeout, $httpHandler);

        $body = ['ssh_public_key' => $keyPair->publicKeyLine];
        if ($name !== null) {
            $body['name'] = $name;
        }

        $payload = $api->post('/shells/agent', $body);

        return new self(
            info: SandboxInfo::fromApi(is_array($payload) ? $payload : []),
            api: $api,
            privateKey: $keyPair->privateKey,
            privateKeyOpenSsh: $keyPair->privateKeyOpenSsh,
        );
    }

    /**
     * Attach to an existing sandbox by UUID.
     *
     * $privateKey is the key whose public half the box was created with: an
     * OpenSSH-format string, or a phpseclib key object (e.g. the ->privateKey
     * of the original Sandbox).
     *
     * @param callable|null $httpHandler Guzzle handler override (for tests).
     *
     * @throws XshellzException The private key cannot be parsed.
     * @throws SandboxNotRunningException The UUID is not among this account's sandboxes.
     */
    public static function connect(
        string $uuid,
        string|PrivateKey $privateKey,
        ?string $apiKey = null,
        ?string $apiUrl = null,
        ?callable $httpHandler = null,
    ): self {
        $key = KeyPair::loadPrivateKey($privateKey);
        $api = new ApiClient($apiKey, $apiUrl, handler: $httpHandler);
        $info = self::find($api, $uuid);

        return new self(
            info: $info,
            api: $api,
            privateKey: $key,
            privateKeyOpenSsh: is_string($privateKey) ? $privateKey : null,
        );
    }

    /**
     * Get the sandbox named $name, creating it if it does not exist -
     * "permanent mode" for a named box that survives process restarts.
     *
     * - Not found: creates the box (like create(name: ...)) and, when a
     *   keystore is enabled, persists the generated private key so a later
     *   getOrCreate() from any process can re-attach.
     * - Found: attaches with a private key ($privateKey wins, else the
     *   keystore entry for $name); a stopped box is start()ed first.
     *
     * $keystore: null uses the default keystore (`~/.xshellz/keys/`), a
     * Keystore instance uses that store, false disables persistence entirely
     * (then re-attaching requires an explicit $privateKey).
     *
     * @param callable|null $httpHandler Guzzle handler override (for tests).
     *
     * @throws MissingKeyException The box exists but no key was passed and
     *     the keystore has none (or is disabled).
     * @throws Exceptions\AuthException
     * @throws Exceptions\QuotaException
     * @throws ApiException
     */
    public static function getOrCreate(
        string $name,
        string|PrivateKey|null $privateKey = null,
        Keystore|false|null $keystore = null,
        ?string $apiKey = null,
        ?string $apiUrl = null,
        float $timeout = 120.0,
        ?callable $httpHandler = null,
    ): self {
        $store = $keystore === false ? null : ($keystore ?? new Keystore());
        $api = new ApiClient($apiKey, $apiUrl, $timeout, $httpHandler);

        $existing = null;
        $payload = $api->get('/shells/agent');
        foreach (is_array($payload) ? $payload : [] as $item) {
            if (is_array($item)) {
                $info = SandboxInfo::fromApi($item);
                if ($info->name === $name) {
                    $existing = $info;
                    break;
                }
            }
        }

        if ($existing === null) {
            $sbx = self::create(
                name: $name,
                apiKey: $apiKey,
                apiUrl: $apiUrl,
                timeout: $timeout,
                httpHandler: $httpHandler,
            );
            if ($store !== null && $sbx->privateKeyOpenSsh !== null) {
                $store->save($name, $sbx->privateKeyOpenSsh);
            }

            return $sbx;
        }

        $keyMaterial = $privateKey;
        if ($keyMaterial === null && $store !== null) {
            $keyMaterial = $store->load($name);
        }
        if ($keyMaterial === null) {
            throw new MissingKeyException(
                "Sandbox '{$name}' already exists but no private key is available for it. "
                . ($store !== null
                    ? "Expected an OpenSSH private key at {$store->path($name)}, or pass privateKey: ... explicitly."
                    : 'The keystore is disabled - pass privateKey: ... explicitly.')
                . ' Alternatively kill() the box and let getOrCreate() recreate it.'
            );
        }

        $sbx = new self(
            info: $existing,
            api: $api,
            privateKey: KeyPair::loadPrivateKey($keyMaterial),
            privateKeyOpenSsh: is_string($keyMaterial) ? $keyMaterial : null,
        );

        if ($existing->status === self::STATUS_STOPPED) {
            $sbx->start();
        }

        return $sbx;
    }

    /**
     * List the account's active sandboxes (a bare array on the wire).
     *
     * @param callable|null $httpHandler Guzzle handler override (for tests).
     *
     * @return list<SandboxInfo>
     */
    public static function list(
        ?string $apiKey = null,
        ?string $apiUrl = null,
        ?callable $httpHandler = null,
    ): array {
        $api = new ApiClient($apiKey, $apiUrl, handler: $httpHandler);
        $payload = $api->get('/shells/agent');

        $infos = [];
        foreach (is_array($payload) ? $payload : [] as $item) {
            if (is_array($item)) {
                $infos[] = SandboxInfo::fromApi($item);
            }
        }

        return $infos;
    }

    /**
     * Read the account's saved boxfile (provisioning template), or null when
     * none is saved (GET /shells/agent/boxfile).
     *
     * The boxfile is an account-level `xshellz.box` manifest seeded into
     * `~/xshellz.box` on every NEW box, where it is applied at provisioning
     * time - use it to preinstall packages so a destroy+recreate reproduces
     * your environment. It does not affect boxes that already exist.
     *
     * @param callable|null $httpHandler Guzzle handler override (for tests).
     */
    public static function getBoxfile(
        ?string $apiKey = null,
        ?string $apiUrl = null,
        ?callable $httpHandler = null,
    ): ?string {
        $api = new ApiClient($apiKey, $apiUrl, handler: $httpHandler);
        $payload = $api->get('/shells/agent/boxfile');
        $manifest = is_array($payload) ? ($payload['manifest'] ?? null) : null;

        return is_string($manifest) ? $manifest : null;
    }

    /**
     * Save (or clear, with null/blank content) the account's boxfile
     * (PUT /shells/agent/boxfile; max 16 KiB).
     *
     * Applied when a NEW box is created - existing boxes are unaffected.
     * Returns the manifest as stored (null when cleared).
     *
     * @param callable|null $httpHandler Guzzle handler override (for tests).
     */
    public static function setBoxfile(
        ?string $content,
        ?string $apiKey = null,
        ?string $apiUrl = null,
        ?callable $httpHandler = null,
    ): ?string {
        $api = new ApiClient($apiKey, $apiUrl, handler: $httpHandler);
        $payload = $api->put('/shells/agent/boxfile', ['manifest' => $content]);
        $manifest = is_array($payload) ? ($payload['manifest'] ?? null) : null;

        return is_string($manifest) ? $manifest : null;
    }

    /**
     * Resolve one sandbox via the list endpoint (there is no GET show route).
     *
     * @throws SandboxNotRunningException
     */
    private static function find(ApiClient $api, string $uuid): SandboxInfo
    {
        $payload = $api->get('/shells/agent');
        foreach (is_array($payload) ? $payload : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $info = SandboxInfo::fromApi($item);
            if ($info->uuid === $uuid) {
                return $info;
            }
        }

        throw new SandboxNotRunningException(
            "Sandbox {$uuid} was not found among this account's active sandboxes."
        );
    }

    // ------------------------------------------------------------------ //
    // Virtual read-only properties
    // ------------------------------------------------------------------ //

    public function __get(string $name): mixed
    {
        return match ($name) {
            'info' => $this->info,
            'uuid' => $this->info->uuid,
            'name' => $this->info->name,
            'status' => $this->info->status,
            'sshHost' => $this->info->sshHost,
            'sshPort' => $this->info->sshPort,
            'sshCommand' => $this->info->sshCommand,
            'privateKey' => $this->privateKey,
            'privateKeyOpenSsh' => $this->privateKeyOpenSsh,
            default => throw new OutOfBoundsException("Undefined property: Sandbox::\${$name}"),
        };
    }

    public function __isset(string $name): bool
    {
        try {
            return $this->__get($name) !== null;
        } catch (OutOfBoundsException) {
            return false;
        }
    }

    // ------------------------------------------------------------------ //
    // Data plane (SSH/SFTP)
    // ------------------------------------------------------------------ //

    /**
     * Run a shell command in the sandbox and wait for it to finish.
     *
     * A non-zero exit code does NOT throw - inspect $result->exitCode.
     * $onStdout/$onStderr receive decoded output chunks as they arrive (the
     * full output is still returned in the result).
     *
     * @param array<string, string>|null $env
     * @param callable(string): void|null $onStdout
     * @param callable(string): void|null $onStderr
     *
     * @throws SandboxNotRunningException The box is not in the "running" state.
     * @throws Exceptions\CommandTimeoutException $timeoutSeconds elapsed before exit.
     */
    public function run(
        string $command,
        ?string $cwd = null,
        ?array $env = null,
        ?float $timeoutSeconds = null,
        ?callable $onStdout = null,
        ?callable $onStderr = null,
    ): CommandResult {
        $transport = $this->getTransport();
        $fullCommand = ShellCommand::build($command, cwd: $cwd, env: $env);

        return $transport->exec(
            $fullCommand,
            timeoutSeconds: $timeoutSeconds,
            onStdout: $onStdout,
            onStderr: $onStderr,
        );
    }

    /**
     * Start a background process in the sandbox and return a JobHandle.
     *
     * The command runs under nohup, detached from the SSH session: it
     * survives run()/close() and keeps going until it exits, is stopped via
     * $job->stop(), or the box itself stops. Combined stdout+stderr is
     * written to `~/.xshellz/jobs/<id>.log` inside the box (see
     * $job->logs()).
     *
     * @param string|null $name Optional label; becomes the job id's prefix.
     *
     * @throws SandboxNotRunningException The box is not in the "running" state.
     */
    public function spawn(string $command, ?string $name = null): JobHandle
    {
        $id = ($name !== null ? Keystore::sanitize($name) . '-' : '') . bin2hex(random_bytes(4));
        $logPath = "~/.xshellz/jobs/{$id}.log";
        $pidPath = "~/.xshellz/jobs/{$id}.pid";

        $result = $this->run(
            'mkdir -p ~/.xshellz/jobs; '
            . 'nohup bash -c ' . ShellCommand::quote($command)
            . " > {$logPath} 2>&1 < /dev/null & "
            . "echo \$! > {$pidPath}; cat {$pidPath}"
        );

        $pid = (int) trim($result->stdout);

        return new JobHandle($this, $id, $pid > 0 ? $pid : null, $logPath);
    }

    /**
     * List background jobs (every log file under `~/.xshellz/jobs/`) with a
     * liveness snapshot.
     *
     * Each handle's ->running reflects liveness at listing time; call
     * ->isRunning() on a handle for a live probe. Finished jobs stay listed
     * (their logs remain) until you remove their files.
     *
     * @return list<JobHandle>
     *
     * @throws SandboxNotRunningException The box is not in the "running" state.
     */
    public function jobs(): array
    {
        $result = $this->run(
            'cd ~/.xshellz/jobs 2>/dev/null || exit 0; '
            . 'for f in *.log; do [ -e "$f" ] || continue; id="${f%.log}"; '
            . 'pid=$(cat "$id.pid" 2>/dev/null); '
            . 'if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then run=1; else run=0; fi; '
            . 'echo "$id ${pid:--} $run"; done'
        );

        $jobs = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if ($fields === false || count($fields) !== 3) {
                continue;
            }
            [$id, $pid, $running] = $fields;
            $jobs[] = new JobHandle(
                sandbox: $this,
                id: $id,
                pid: is_numeric($pid) ? (int) $pid : null,
                logPath: "~/.xshellz/jobs/{$id}.log",
                running: $running === '1',
            );
        }

        return $jobs;
    }

    /**
     * Run a snippet of code in the sandbox - handy for executing
     * AI-generated code without shell-quoting headaches.
     *
     * Supported languages: "python" (python3), "node", "bash", "ruby",
     * "php". The code is written to a temp file inside the box, executed
     * with the language's interpreter, and the temp file is deleted when the
     * run finishes (whatever the exit code). Semantics match run(): a
     * non-zero exit does NOT throw.
     *
     * @param array<string, string>|null $env
     * @param callable(string): void|null $onStdout
     * @param callable(string): void|null $onStderr
     *
     * @throws XshellzException The language is not supported.
     * @throws SandboxNotRunningException The box is not in the "running" state.
     * @throws Exceptions\CommandTimeoutException $timeoutSeconds elapsed before exit.
     */
    public function runCode(
        string $language,
        string $code,
        ?string $cwd = null,
        ?array $env = null,
        ?float $timeoutSeconds = null,
        ?callable $onStdout = null,
        ?callable $onStderr = null,
    ): CommandResult {
        $key = strtolower(trim($language));
        if (! isset(self::INTERPRETERS[$key])) {
            throw new XshellzException(
                "Unsupported runCode() language '{$language}'. Supported: "
                . implode(', ', array_keys(self::INTERPRETERS)) . '.'
            );
        }
        [$interpreter, $extension] = self::INTERPRETERS[$key];

        $path = '/tmp/xshellz-code-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $this->writeFile($path, $code);

        return $this->run(
            "{$interpreter} {$path}; __xs_rc=\$?; rm -f {$path}; exit \$__xs_rc",
            cwd: $cwd,
            env: $env,
            timeoutSeconds: $timeoutSeconds,
            onStdout: $onStdout,
            onStderr: $onStderr,
        );
    }

    /**
     * Write $data to $path inside the sandbox (SFTP).
     *
     * @throws SandboxNotRunningException
     */
    public function writeFile(string $path, string $data): void
    {
        $this->getTransport()->writeFile($path, $data);
    }

    /**
     * Read and return the contents of $path inside the sandbox (SFTP).
     *
     * @throws SandboxNotRunningException
     */
    public function readFile(string $path): string
    {
        return $this->getTransport()->readFile($path);
    }

    /**
     * Upload a local file into the sandbox (SFTP).
     *
     * @throws SandboxNotRunningException
     */
    public function upload(string $localPath, string $remotePath): void
    {
        $this->getTransport()->upload($localPath, $remotePath);
    }

    /**
     * Download a file from the sandbox to a local path (SFTP).
     *
     * @throws SandboxNotRunningException
     */
    public function download(string $remotePath, string $localPath): void
    {
        $this->getTransport()->download($remotePath, $localPath);
    }

    // ------------------------------------------------------------------ //
    // Lifecycle (control plane)
    // ------------------------------------------------------------------ //

    /**
     * Resume an idle-stopped box (POST /shells/agent/{uuid}/start).
     *
     * Free-tier boxes idle-stop after ~30 minutes; this brings the same box
     * (same /home, same authorized key) back to "running".
     *
     * @throws SandboxNotRunningException There is no stopped box to start (it
     *     may already be running, suspended, or deleted).
     */
    public function start(): void
    {
        try {
            $payload = $this->api->post('/shells/agent/' . $this->info->uuid . '/start');
        } catch (ApiException $exception) {
            if ($exception->statusCode === 404) {
                throw new SandboxNotRunningException(
                    "Sandbox {$this->info->uuid} has no stopped box to start - it may "
                    . 'already be running, suspended, or deleted.',
                    previous: $exception,
                );
            }
            throw $exception;
        }

        $this->info = SandboxInfo::fromApi(is_array($payload) ? $payload : []);
        $this->closeTransport();
    }

    /**
     * Reboot a running box (POST /shells/agent/{uuid}/restart).
     *
     * Re-runs the box's entrypoint; `/home` is preserved. The SSH transport
     * is closed and will reconnect lazily on the next data-plane call.
     *
     * @throws SandboxNotRunningException The box is not running (or gone).
     */
    public function restart(): void
    {
        try {
            $payload = $this->api->post('/shells/agent/' . $this->info->uuid . '/restart');
        } catch (ApiException $exception) {
            if ($exception->statusCode === 404) {
                throw new SandboxNotRunningException(
                    "Sandbox {$this->info->uuid} is not running (or no longer exists), so it cannot be restarted.",
                    previous: $exception,
                );
            }
            throw $exception;
        }

        $this->info = SandboxInfo::fromApi(is_array($payload) ? $payload : []);
        $this->closeTransport();
    }

    /**
     * Live resource usage for the box (GET /shells/agent/{uuid}/stats).
     *
     * Returns the wire fields as-is: mem_used_mb, mem_limit_mb,
     * mem_allowed_mb, cpu_percent, cpu_allowed_vcpus, cpu_throttled_periods,
     * pids_current, pids_allowed, disk_used_mb, disk_allowed_mb, net_rx_mb,
     * net_tx_mb, blk_read_mb, blk_write_mb.
     *
     * @return array<string, int|float>
     *
     * @throws ApiException 404 when the box is not running, 503 when live
     *     stats are temporarily unavailable.
     */
    public function stats(): array
    {
        $payload = $this->api->get('/shells/agent/' . $this->info->uuid . '/stats');

        /** @var array<string, int|float> */
        return is_array($payload) ? $payload : [];
    }

    /**
     * Top processes + active session count (GET /shells/agent/{uuid}/procs).
     *
     * Returns the wire shape as-is: `procs` (list of {pid, comm, cpu, mem}),
     * `sessions` (active SSH session count), `agents` (detected agent
     * processes), `disk_used_mb`, `disk_allowed_mb`.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 404 when the box is not running, 503 when the
     *     process list is temporarily unavailable.
     */
    public function procs(): array
    {
        $payload = $this->api->get('/shells/agent/' . $this->info->uuid . '/procs');

        /** @var array<string, mixed> */
        return is_array($payload) ? $payload : [];
    }

    /**
     * Mint a fresh signed web-terminal URL (GET /shells/agent/{uuid}/terminal).
     *
     * Open it in a browser for a root terminal on the box. The embedded
     * token is short-lived (about 1 hour) - call this again for a fresh URL
     * rather than storing one.
     *
     * @throws ApiException 404 when the box is not running.
     */
    public function terminalUrl(): string
    {
        $payload = $this->api->get('/shells/agent/' . $this->info->uuid . '/terminal');

        return is_array($payload) ? (string) ($payload['url'] ?? '') : '';
    }

    /**
     * Destroy the sandbox (DELETE /shells/agent/{uuid}).
     *
     * Idempotent: a 404 (already gone) is swallowed, a repeat call is a
     * no-op, and after detach() the box is kept alive (kill() does nothing) -
     * so `finally { $sbx->kill(); }` is always safe teardown.
     */
    public function kill(): void
    {
        $this->closeTransport();
        if ($this->killed || $this->detached) {
            return;
        }

        try {
            $this->api->delete('/shells/agent/' . $this->info->uuid);
        } catch (ApiException $exception) {
            if ($exception->statusCode !== 404) {
                throw $exception;
            }
        }

        $this->killed = true;
    }

    /**
     * Keep the sandbox alive: after detach(), kill() becomes a no-op, so a
     * shared `finally { $sbx->kill(); }` teardown leaves the box running.
     *
     * Persist ->privateKeyOpenSsh and ->uuid to re-attach later with
     * Sandbox::connect().
     */
    public function detach(): void
    {
        $this->detached = true;
    }

    /**
     * Re-fetch this sandbox's state from the control plane.
     *
     * @throws SandboxNotRunningException The sandbox no longer exists.
     */
    public function refresh(): SandboxInfo
    {
        $this->info = self::find($this->api, $this->info->uuid);

        return $this->info;
    }

    /**
     * Close the SSH connection (keeps the box alive).
     */
    public function close(): void
    {
        $this->closeTransport();
    }

    public function __destruct()
    {
        $this->closeTransport();
    }

    // ------------------------------------------------------------------ //
    // Internals
    // ------------------------------------------------------------------ //

    /**
     * @throws SandboxNotRunningException
     * @throws XshellzException
     */
    private function getTransport(): SshTransportInterface
    {
        if ($this->transport !== null) {
            return $this->transport;
        }

        if ($this->info->status !== self::STATUS_RUNNING) {
            throw new SandboxNotRunningException(
                "Sandbox {$this->info->uuid} is '{$this->info->status}', not 'running'. "
                . 'Call start() to resume an idle-stopped box.'
            );
        }
        if ($this->info->sshHost === null || $this->info->sshPort === null) {
            throw new SandboxNotRunningException(
                "Sandbox {$this->info->uuid} has no SSH endpoint yet (host/port unknown)."
            );
        }
        if ($this->privateKey === null) {
            throw new XshellzException(
                'No private key available for this sandbox - attach with '
                . 'Sandbox::connect(uuid, privateKey: ...).'
            );
        }

        $this->transport = new PhpseclibTransport(
            host: $this->info->sshHost,
            port: $this->info->sshPort,
            privateKey: $this->privateKey,
        );

        return $this->transport;
    }

    private function closeTransport(): void
    {
        if ($this->transport !== null) {
            $this->transport->close();
            $this->transport = null;
        }
    }
}
