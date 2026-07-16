<?php

declare(strict_types=1);

namespace Xshellz;

use OutOfBoundsException;
use phpseclib3\Crypt\Common\PrivateKey;
use Xshellz\Exceptions\ApiException;
use Xshellz\Exceptions\SandboxNotRunningException;
use Xshellz\Exceptions\XshellzException;
use Xshellz\Ssh\PhpseclibTransport;
use Xshellz\Ssh\ShellCommand;
use Xshellz\Ssh\SshTransportInterface;

/**
 * A remote sandbox: control plane over HTTPS, data plane over SSH.
 *
 * Create one with Sandbox::create(), attach to an existing one with
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
