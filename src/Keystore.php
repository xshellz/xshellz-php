<?php

declare(strict_types=1);

namespace Xshellz;

use Xshellz\Exceptions\XshellzException;

/**
 * Local on-disk store for sandbox private keys, one file per sandbox name.
 *
 * Used by Sandbox::getOrCreate() so a named sandbox can be re-attached from a
 * later process without hand-carrying the key. Layout: one OpenSSH private
 * key per file under the keystore directory (default `~/.xshellz/keys/`),
 * named after the sanitized sandbox name.
 *
 * Security note: keys are stored in PLAINTEXT on disk, readable only by your
 * user (directory 0700, files 0600). Anyone with that file has root SSH to
 * the sandbox. Delete the file (or the sandbox) to revoke.
 */
final class Keystore
{
    private readonly string $directory;

    /**
     * @param string|null $directory Keystore directory. Defaults to
     *     `$HOME/.xshellz/keys`.
     *
     * @throws XshellzException No directory was given and $HOME is not set.
     */
    public function __construct(?string $directory = null)
    {
        $dir = $directory;
        if ($dir === null) {
            $home = trim((string) (getenv('HOME') ?: ''));
            if ($home === '') {
                throw new XshellzException(
                    'Cannot locate the default keystore: the HOME environment variable '
                    . 'is not set. Pass an explicit directory to new Keystore(...).'
                );
            }
            $dir = rtrim($home, '/') . '/.xshellz/keys';
        }

        $this->directory = rtrim($dir, '/');
    }

    /**
     * The directory keys are stored in.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The path where the key for $name is (or would be) stored.
     */
    public function path(string $name): string
    {
        return $this->directory . '/' . self::sanitize($name) . '.key';
    }

    /**
     * Persist an OpenSSH private key for $name (file mode 0600).
     *
     * @return string The path the key was written to.
     *
     * @throws XshellzException The directory or file could not be written.
     */
    public function save(string $name, string $privateKeyOpenSsh): string
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new XshellzException("Could not create the keystore directory {$this->directory}.");
        }

        $path = $this->path($name);
        if (@file_put_contents($path, $privateKeyOpenSsh) === false) {
            throw new XshellzException("Could not write the private key to {$path}.");
        }
        @chmod($path, 0600);

        return $path;
    }

    /**
     * Load the stored key for $name, or null when none exists.
     */
    public function load(string $name): ?string
    {
        $path = $this->path($name);
        if (! is_file($path)) {
            return null;
        }

        $key = @file_get_contents($path);

        return $key === false ? null : $key;
    }

    /**
     * Remove the stored key for $name (a missing file is a no-op).
     */
    public function delete(string $name): void
    {
        $path = $this->path($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Sanitize a sandbox name into a safe filename component.
     */
    public static function sanitize(string $name): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $safe = trim($safe, '-.');

        return $safe === '' ? 'sandbox' : $safe;
    }
}
