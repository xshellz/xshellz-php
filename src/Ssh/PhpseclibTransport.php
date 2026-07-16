<?php

declare(strict_types=1);

namespace Xshellz\Ssh;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Xshellz\CommandResult;
use Xshellz\Exceptions\CommandTimeoutException;
use Xshellz\Exceptions\XshellzException;

/**
 * Real SSH transport: exec over an SSH2 session, files over SFTP.
 *
 * Speaks SSH as `root` to the sandbox (fake-root userns inside gVisor).
 *
 * Host keys are auto-accepted: sandbox host keys are minted at spawn time, so
 * there is no out-of-band fingerprint to verify against. (phpseclib performs
 * no known_hosts verification by default, which matches this policy.)
 *
 * Streaming: stdout is streamed to the onStdout callback chunk-by-chunk as it
 * arrives (phpseclib exec callback). stderr travels on the SSH extended-data
 * channel, which phpseclib buffers separately in quiet mode - it is delivered
 * to onStderr once the command finishes (and always in full on the result).
 */
final class PhpseclibTransport implements SshTransportInterface
{
    private readonly SSH2 $ssh;

    private ?SFTP $sftp = null;

    /**
     * @throws XshellzException When the SSH connection or authentication fails.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly PrivateKey $privateKey,
        private readonly string $username = 'root',
        private readonly int $connectTimeoutSeconds = 30,
    ) {
        $ssh = new SSH2($host, $port, $connectTimeoutSeconds);
        if (! $ssh->login($username, $privateKey)) {
            throw new XshellzException(
                "SSH authentication to {$username}@{$host}:{$port} failed. "
                . 'Is this the private key whose public half the sandbox was created with?'
            );
        }
        $ssh->enableQuietMode();
        $this->ssh = $ssh;
    }

    public function exec(
        string $command,
        ?float $timeoutSeconds = null,
        ?callable $onStdout = null,
        ?callable $onStderr = null,
    ): CommandResult {
        $this->ssh->setTimeout($timeoutSeconds !== null ? (int) ceil($timeoutSeconds) : 0);

        $stdout = '';
        $this->ssh->exec($command, function (string $chunk) use (&$stdout, $onStdout): void {
            $stdout .= $chunk;
            if ($onStdout !== null) {
                $onStdout($chunk);
            }
        });

        if ($timeoutSeconds !== null && $this->ssh->isTimeout()) {
            throw new CommandTimeoutException(
                "Command did not finish within {$timeoutSeconds} seconds: {$command}"
            );
        }

        $stderr = (string) $this->ssh->getStdError();
        if ($stderr !== '' && $onStderr !== null) {
            $onStderr($stderr);
        }

        $exitStatus = $this->ssh->getExitStatus();

        return new CommandResult(
            stdout: $stdout,
            stderr: $stderr,
            exitCode: is_int($exitStatus) ? $exitStatus : -1,
        );
    }

    public function readFile(string $path): string
    {
        $data = $this->sftp()->get($path);
        if (! is_string($data)) {
            throw new XshellzException("SFTP read of {$path} failed.");
        }

        return $data;
    }

    public function writeFile(string $path, string $data): void
    {
        if (! $this->sftp()->put($path, $data)) {
            throw new XshellzException("SFTP write to {$path} failed.");
        }
    }

    public function upload(string $localPath, string $remotePath): void
    {
        if (! $this->sftp()->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new XshellzException("SFTP upload of {$localPath} to {$remotePath} failed.");
        }
    }

    public function download(string $remotePath, string $localPath): void
    {
        if ($this->sftp()->get($remotePath, $localPath) === false) {
            throw new XshellzException("SFTP download of {$remotePath} to {$localPath} failed.");
        }
    }

    public function close(): void
    {
        if ($this->sftp !== null) {
            $this->sftp->disconnect();
            $this->sftp = null;
        }
        $this->ssh->disconnect();
    }

    /**
     * @throws XshellzException When the SFTP connection or authentication fails.
     */
    private function sftp(): SFTP
    {
        if ($this->sftp === null) {
            $sftp = new SFTP($this->host, $this->port, $this->connectTimeoutSeconds);
            if (! $sftp->login($this->username, $this->privateKey)) {
                throw new XshellzException(
                    "SFTP authentication to {$this->username}@{$this->host}:{$this->port} failed."
                );
            }
            $this->sftp = $sftp;
        }

        return $this->sftp;
    }
}
