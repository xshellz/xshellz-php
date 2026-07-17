<?php

declare(strict_types=1);

namespace Xshellz\Tests\Support;

use Xshellz\CommandResult;
use Xshellz\Ssh\SshTransportInterface;

/**
 * In-memory stand-in for the SSH data plane.
 */
final class FakeTransport implements SshTransportInterface
{
    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $files = [];

    public bool $closed = false;

    public CommandResult $nextResult;

    /** @var list<CommandResult> Shifted one per exec() before falling back to $nextResult. */
    public array $queuedResults = [];

    /** @var list<array{0: 'stdout'|'stderr', 1: string}> */
    public array $streamChunks = [];

    public function __construct()
    {
        $this->nextResult = new CommandResult(stdout: 'out', stderr: '', exitCode: 0);
    }

    public function exec(
        string $command,
        ?float $timeoutSeconds = null,
        ?callable $onStdout = null,
        ?callable $onStderr = null,
    ): CommandResult {
        $this->commands[] = $command;

        foreach ($this->streamChunks as [$stream, $chunk]) {
            if ($stream === 'stdout' && $onStdout !== null) {
                $onStdout($chunk);
            }
            if ($stream === 'stderr' && $onStderr !== null) {
                $onStderr($chunk);
            }
        }

        if ($this->queuedResults !== []) {
            return array_shift($this->queuedResults);
        }

        return $this->nextResult;
    }

    public function readFile(string $path): string
    {
        return $this->files[$path];
    }

    public function writeFile(string $path, string $data): void
    {
        $this->files[$path] = $data;
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->files[$remotePath] = (string) file_get_contents($localPath);
    }

    public function download(string $remotePath, string $localPath): void
    {
        file_put_contents($localPath, $this->files[$remotePath]);
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
