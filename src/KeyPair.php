<?php

declare(strict_types=1);

namespace Xshellz;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;
use Xshellz\Exceptions\XshellzException;

/**
 * An in-memory ed25519 SSH keypair.
 *
 * The keypair is generated per Sandbox::create() and never touches disk; the
 * control plane only ever sees the public half.
 */
final readonly class KeyPair
{
    public const DEFAULT_KEY_COMMENT = 'xshellz-sdk';

    /**
     * @param PrivateKey $privateKey The phpseclib key object used to authenticate SSH sessions.
     * @param string $privateKeyOpenSsh The private key serialized in OpenSSH format (useful to
     *     persist if you plan to detach() and connect() again later from another process).
     * @param string $publicKeyLine The single-line OpenSSH public key
     *     ("ssh-ed25519 <base64> <comment>") sent to the control plane.
     */
    public function __construct(
        public PrivateKey $privateKey,
        public string $privateKeyOpenSsh,
        public string $publicKeyLine,
    ) {
    }

    /**
     * Generate a fresh in-memory ed25519 keypair.
     */
    public static function generate(string $comment = self::DEFAULT_KEY_COMMENT): self
    {
        $key = EC::createKey('Ed25519');

        return new self(
            privateKey: $key,
            privateKeyOpenSsh: $key->toString('OpenSSH', ['comment' => $comment]),
            publicKeyLine: $key->getPublicKey()->toString('OpenSSH', ['comment' => $comment]),
        );
    }

    /**
     * Load a private key from an OpenSSH/PEM-format string or a phpseclib key.
     *
     * @throws XshellzException When the key material cannot be parsed as a private key.
     */
    public static function loadPrivateKey(string|PrivateKey $privateKey): PrivateKey
    {
        if ($privateKey instanceof PrivateKey) {
            return $privateKey;
        }

        try {
            $loaded = PublicKeyLoader::load($privateKey);
        } catch (Throwable $exception) {
            throw new XshellzException(
                'Could not load the private key. Expected an unencrypted '
                . 'OpenSSH/PEM-format private key (ed25519, RSA, or ECDSA). '
                . "Details: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! $loaded instanceof PrivateKey) {
            throw new XshellzException(
                'Could not load the private key: the supplied key material is not a private key.'
            );
        }

        return $loaded;
    }
}
