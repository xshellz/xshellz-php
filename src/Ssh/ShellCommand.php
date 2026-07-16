<?php

declare(strict_types=1);

namespace Xshellz\Ssh;

use Xshellz\Exceptions\XshellzException;

/**
 * Builds the remote shell command line for Sandbox::run().
 */
final class ShellCommand
{
    private function __construct()
    {
    }

    /**
     * Wrap a command with optional `cd` and environment exports.
     *
     * Environment variable names are validated (sshd rarely honours
     * AcceptEnv, so variables are exported in the remote shell instead).
     *
     * @param array<string, string>|null $env
     *
     * @throws XshellzException When an environment variable name is invalid.
     */
    public static function build(string $command, ?string $cwd = null, ?array $env = null): string
    {
        $parts = [];

        if ($env !== null && $env !== []) {
            $exports = [];
            foreach ($env as $name => $value) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name) !== 1) {
                    throw new XshellzException("Invalid environment variable name: {$name}");
                }
                $exports[] = $name . '=' . self::quote((string) $value);
            }
            $parts[] = 'export ' . implode(' ', $exports);
        }

        if ($cwd !== null && $cwd !== '') {
            $parts[] = 'cd ' . self::quote($cwd);
        }

        $parts[] = $command;

        return implode(' && ', $parts);
    }

    /**
     * POSIX single-quote a value for the remote shell (safe values pass
     * through unquoted). PHP's escapeshellarg() is locale/OS dependent, so
     * the SDK quotes for the remote POSIX shell itself.
     */
    public static function quote(string $value): string
    {
        if ($value !== '' && preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $value) === 1) {
            return $value;
        }

        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
