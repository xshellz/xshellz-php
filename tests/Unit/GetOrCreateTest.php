<?php

declare(strict_types=1);

use Xshellz\Exceptions\MissingKeyException;
use Xshellz\KeyPair;
use Xshellz\Keystore;
use Xshellz\Sandbox;

/**
 * A keystore rooted in a throwaway temp directory.
 */
function tempKeystore(): Keystore
{
    return new Keystore(sys_get_temp_dir() . '/xshellz-goc-test-' . bin2hex(random_bytes(4)));
}

test('getOrCreate creates the box when the name is not taken and persists the key', function (): void {
    $store = tempKeystore();
    $history = [];
    $handler = mockHandler([
        jsonResponse(200, []), // list: nothing exists
        jsonResponse(200, shellPayload(['name' => 'perma'])), // create
    ], $history);

    $sbx = Sandbox::getOrCreate('perma', keystore: $store, apiKey: 'k', httpHandler: $handler);

    expect($history)->toHaveCount(2)
        ->and($history[0]['request']->getMethod())->toBe('GET')
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v1/shells/agent')
        ->and($history[1]['request']->getMethod())->toBe('POST');

    $body = json_decode((string) $history[1]['request']->getBody(), true);
    expect($body['name'])->toBe('perma')
        ->and($body['ssh_public_key'])->toStartWith('ssh-ed25519 ');

    // The generated key was persisted for a later getOrCreate() to find.
    $stored = $store->load('perma');
    expect($stored)->toBe($sbx->privateKeyOpenSsh)
        ->and((string) $stored)->toContain('OPENSSH PRIVATE KEY');
});

test('getOrCreate attaches to an existing box using the keystore key', function (): void {
    $store = tempKeystore();
    $keyPair = KeyPair::generate();
    $store->save('perma', $keyPair->privateKeyOpenSsh);

    $history = [];
    $handler = mockHandler([jsonResponse(200, [shellPayload(['name' => 'perma'])])], $history);

    $sbx = Sandbox::getOrCreate('perma', keystore: $store, apiKey: 'k', httpHandler: $handler);

    expect($history)->toHaveCount(1) // list only - no create, box was found
        ->and($sbx->name)->toBe('perma')
        ->and($sbx->status)->toBe('running')
        ->and($sbx->privateKey)->not->toBeNull()
        ->and($sbx->privateKeyOpenSsh)->toBe($keyPair->privateKeyOpenSsh);
});

test('getOrCreate starts a found box that is stopped', function (): void {
    $store = tempKeystore();
    $store->save('perma', KeyPair::generate()->privateKeyOpenSsh);

    $history = [];
    $handler = mockHandler([
        jsonResponse(200, [shellPayload(['name' => 'perma', 'status' => 'stopped'])]),
        jsonResponse(200, shellPayload(['name' => 'perma', 'status' => 'running'])),
    ], $history);

    $sbx = Sandbox::getOrCreate('perma', keystore: $store, apiKey: 'k', httpHandler: $handler);

    expect($history)->toHaveCount(2)
        ->and($history[1]['request']->getMethod())->toBe('POST')
        ->and($history[1]['request']->getUri()->getPath())
        ->toBe('/v1/shells/agent/11111111-2222-3333-4444-555555555555/start')
        ->and($sbx->status)->toBe('running');
});

test('an explicit privateKey beats the keystore', function (): void {
    $store = tempKeystore();
    $store->save('perma', KeyPair::generate()->privateKeyOpenSsh); // decoy
    $explicit = KeyPair::generate();

    $sbx = Sandbox::getOrCreate(
        'perma',
        privateKey: $explicit->privateKeyOpenSsh,
        keystore: $store,
        apiKey: 'k',
        httpHandler: mockHandler([jsonResponse(200, [shellPayload(['name' => 'perma'])])]),
    );

    expect($sbx->privateKeyOpenSsh)->toBe($explicit->privateKeyOpenSsh);
});

test('a found box with no stored key throws MissingKeyException naming the expected path', function (): void {
    $store = tempKeystore();

    expect(fn (): Sandbox => Sandbox::getOrCreate(
        'perma',
        keystore: $store,
        apiKey: 'k',
        httpHandler: mockHandler([jsonResponse(200, [shellPayload(['name' => 'perma'])])]),
    ))->toThrow(MissingKeyException::class, $store->path('perma'));
});

test('with the keystore disabled a found box requires an explicit key', function (): void {
    expect(fn (): Sandbox => Sandbox::getOrCreate(
        'perma',
        keystore: false,
        apiKey: 'k',
        httpHandler: mockHandler([jsonResponse(200, [shellPayload(['name' => 'perma'])])]),
    ))->toThrow(MissingKeyException::class, 'keystore is disabled');
});

test('with the keystore disabled a missing box is still created (create-only mode)', function (): void {
    $history = [];
    $handler = mockHandler([
        jsonResponse(200, [shellPayload(['name' => 'other-box'])]), // name not matched
        jsonResponse(200, shellPayload(['name' => 'perma'])),
    ], $history);

    $sbx = Sandbox::getOrCreate('perma', keystore: false, apiKey: 'k', httpHandler: $handler);

    expect($history[1]['request']->getMethod())->toBe('POST')
        ->and($sbx->name)->toBe('perma')
        ->and($sbx->privateKeyOpenSsh)->toContain('OPENSSH PRIVATE KEY');
});
