<?php

declare(strict_types=1);

use Xshellz\Exceptions\XshellzException;
use Xshellz\KeyPair;
use Xshellz\Keystore;

/**
 * A fresh throwaway keystore directory per test.
 */
function keystoreDir(): string
{
    $dir = sys_get_temp_dir() . '/xshellz-keystore-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

beforeEach(function (): void {
    $this->originalHome = getenv('HOME');
});

afterEach(function (): void {
    putenv($this->originalHome === false ? 'HOME' : 'HOME=' . $this->originalHome);
});

test('the default keystore lives under $HOME/.xshellz/keys', function (): void {
    putenv('HOME=/home/someone');

    $store = new Keystore;

    expect($store->directory())->toBe('/home/someone/.xshellz/keys')
        ->and($store->path('demo'))->toBe('/home/someone/.xshellz/keys/demo.key');
});

test('the default keystore without HOME throws a helpful error', function (): void {
    putenv('HOME');

    expect(fn (): Keystore => new Keystore)
        ->toThrow(XshellzException::class, 'HOME');
});

test('save/load round-trips the key with 0600 file and 0700 directory perms', function (): void {
    $dir = keystoreDir() . '/nested/keys'; // exercises recursive mkdir
    $store = new Keystore($dir);
    $key = KeyPair::generate()->privateKeyOpenSsh;

    $path = $store->save('demo', $key);

    expect($path)->toBe($dir . '/demo.key')
        ->and($store->load('demo'))->toBe($key)
        ->and(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600')
        ->and(substr(sprintf('%o', fileperms($dir)), -4))->toBe('0700');
});

test('load returns null for an unknown name', function (): void {
    $store = new Keystore(keystoreDir());

    expect($store->load('never-saved'))->toBeNull();
});

test('delete removes the key and is a no-op when absent', function (): void {
    $store = new Keystore(keystoreDir());
    $store->save('demo', 'KEY');

    $store->delete('demo');
    $store->delete('demo'); // idempotent

    expect($store->load('demo'))->toBeNull()
        ->and(is_file($store->path('demo')))->toBeFalse();
});

test('names are sanitized into safe filename components', function (): void {
    expect(Keystore::sanitize('my box!'))->toBe('my-box')
        ->and(Keystore::sanitize('../etc/passwd'))->toBe('etc-passwd')
        ->and(Keystore::sanitize('build_v1.2'))->toBe('build_v1.2')
        ->and(Keystore::sanitize('///'))->toBe('sandbox');
});

test('path uses the sanitized name', function (): void {
    $store = new Keystore('/keys');

    expect($store->path('my box!'))->toBe('/keys/my-box.key');
});
