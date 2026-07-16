<?php

declare(strict_types=1);

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use Xshellz\Exceptions\XshellzException;
use Xshellz\KeyPair;

/**
 * Extracts the base64 blob (second field) from an OpenSSH public key line.
 */
function publicKeyBlob(string $publicKeyLine): string
{
    return explode(' ', $publicKeyLine)[1];
}

test('public key line is valid single-line OpenSSH format with the SDK comment', function (): void {
    $keyPair = KeyPair::generate();

    expect($keyPair->publicKeyLine)->toMatch('#^ssh-ed25519 [A-Za-z0-9+/]+={0,2} xshellz-sdk$#');

    // The base64 blob decodes and declares the ed25519 key type.
    $blob = base64_decode(publicKeyBlob($keyPair->publicKeyLine), true);
    expect($blob)->toBeString()
        ->and(str_contains((string) $blob, 'ssh-ed25519'))->toBeTrue();
});

test('public key matches the server-side validation regex (CreateAgentShellRequest)', function (): void {
    $serverRegex = '#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-[a-z0-9-]+|sk-ssh-ed25519@openssh\.com'
        . '|sk-ecdsa-sha2-[a-z0-9-]+@openssh\.com)\s+[A-Za-z0-9+/=]+(\s+.*)?$#';

    $keyPair = KeyPair::generate();

    expect($keyPair->publicKeyLine)->toMatch($serverRegex);
});

test('the private key round-trips through PublicKeyLoader and matches the public half', function (): void {
    $keyPair = KeyPair::generate();

    expect($keyPair->privateKeyOpenSsh)->toContain('OPENSSH PRIVATE KEY');

    $loaded = PublicKeyLoader::load($keyPair->privateKeyOpenSsh);

    expect($loaded)->toBeInstanceOf(EC\PrivateKey::class);

    /** @var EC\PrivateKey $loaded */
    $reDerivedPublic = $loaded->getPublicKey()->toString('OpenSSH', ['comment' => KeyPair::DEFAULT_KEY_COMMENT]);

    expect(publicKeyBlob($reDerivedPublic))->toBe(publicKeyBlob($keyPair->publicKeyLine));
});

test('each keypair is unique', function (): void {
    expect(KeyPair::generate()->publicKeyLine)
        ->not->toBe(KeyPair::generate()->publicKeyLine);
});

test('loadPrivateKey accepts an OpenSSH string and a key object', function (): void {
    $keyPair = KeyPair::generate();

    $fromString = KeyPair::loadPrivateKey($keyPair->privateKeyOpenSsh);
    $fromObject = KeyPair::loadPrivateKey($keyPair->privateKey);

    expect($fromString)->toBeInstanceOf(PrivateKey::class)
        ->and($fromObject)->toBe($keyPair->privateKey);

    /** @var EC\PrivateKey $fromString */
    $reDerivedPublic = $fromString->getPublicKey()->toString('OpenSSH');
    expect(publicKeyBlob($reDerivedPublic))->toBe(publicKeyBlob($keyPair->publicKeyLine));
});

test('loadPrivateKey rejects garbage', function (): void {
    expect(fn (): PrivateKey => KeyPair::loadPrivateKey('not a key at all'))
        ->toThrow(XshellzException::class, 'Could not load the private key');
});

test('loadPrivateKey rejects public key material', function (): void {
    $keyPair = KeyPair::generate();

    expect(fn (): PrivateKey => KeyPair::loadPrivateKey($keyPair->publicKeyLine))
        ->toThrow(XshellzException::class);
});
