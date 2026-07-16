<?php

declare(strict_types=1);

namespace Xshellz;

/**
 * The outcome of a single Sandbox::run() invocation.
 *
 * A non-zero exit code does NOT throw - it is data, exactly like a local
 * subprocess call.
 */
final readonly class CommandResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
    ) {
    }

    /**
     * True when the command exited with status 0.
     */
    public function ok(): bool
    {
        return $this->exitCode === 0;
    }
}
