<?php

declare(strict_types=1);

namespace Xshellz\Ssh;

use Xshellz\CommandResult;

/**
 * Minimal data-plane surface the Sandbox needs, behind an interface so the
 * SSH layer is unit-testable (and so v1's HTTP/SSE transport is a drop-in).
 */
interface SshTransportInterface
{
    /**
     * Execute a command and wait for it to finish.
     *
     * @param callable(string): void|null $onStdout Receives decoded stdout chunks as they arrive.
     * @param callable(string): void|null $onStderr Receives decoded stderr chunks as they arrive.
     *
     * @throws \Xshellz\Exceptions\CommandTimeoutException
     * @throws \Xshellz\Exceptions\XshellzException
     */
    public function exec(
        string $command,
        ?float $timeoutSeconds = null,
        ?callable $onStdout = null,
        ?callable $onStderr = null,
    ): CommandResult;

    /**
     * @throws \Xshellz\Exceptions\XshellzException
     */
    public function readFile(string $path): string;

    /**
     * @throws \Xshellz\Exceptions\XshellzException
     */
    public function writeFile(string $path, string $data): void;

    /**
     * @throws \Xshellz\Exceptions\XshellzException
     */
    public function upload(string $localPath, string $remotePath): void;

    /**
     * @throws \Xshellz\Exceptions\XshellzException
     */
    public function download(string $remotePath, string $localPath): void;

    public function close(): void;
}
