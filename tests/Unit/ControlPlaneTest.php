<?php

declare(strict_types=1);

use Xshellz\ApiClient;
use Xshellz\Exceptions\ApiException;
use Xshellz\Exceptions\AuthException;
use Xshellz\Exceptions\QuotaException;
use Xshellz\Exceptions\SandboxNotRunningException;
use Xshellz\KeyPair;
use Xshellz\Sandbox;
use Xshellz\SandboxInfo;

// --------------------------------------------------------------------- //
// create()
// --------------------------------------------------------------------- //

test('create posts the generated public key and returns a running sandbox', function (): void {
    $history = [];
    $handler = mockHandler([jsonResponse(200, shellPayload(['name' => 'my-box']))], $history);

    $sbx = Sandbox::create(
        name: 'my-box',
        apiKey: 'pat-123',
        apiUrl: 'https://api.staging.example/v1',
        httpHandler: $handler,
    );

    expect($history)->toHaveCount(1);
    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://api.staging.example/v1/shells/agent')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer pat-123');

    $body = json_decode((string) $request->getBody(), true);
    expect($body['name'])->toBe('my-box')
        ->and($body['ssh_public_key'])->toMatch('#^ssh-ed25519 [A-Za-z0-9+/=]+( .*)?$#');

    expect($sbx->uuid)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($sbx->status)->toBe('running')
        ->and($sbx->sshHost)->toBe('shellus1.xshellz.com')
        ->and($sbx->sshPort)->toBe(42001)
        ->and($sbx->sshCommand)->toBe('ssh -p 42001 root@shellus1.xshellz.com')
        ->and($sbx->name)->toBe('my-box')
        ->and($sbx->privateKey)->not->toBeNull()
        ->and($sbx->privateKeyOpenSsh)->toContain('OPENSSH PRIVATE KEY');
});

test('create omits the name field when not given', function (): void {
    $history = [];
    $handler = mockHandler([jsonResponse(200, shellPayload())], $history);

    Sandbox::create(apiKey: 'k', httpHandler: $handler);

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body)->toHaveKey('ssh_public_key')
        ->and($body)->not->toHaveKey('name');
});

test('a missing API key throws a helpful AuthException', function (): void {
    expect(fn (): Sandbox => Sandbox::create())
        ->toThrow(AuthException::class, 'XSHELLZ_API_KEY');
});

test('the API key and URL are read from the environment', function (): void {
    putenv('XSHELLZ_API_KEY=env-key');
    putenv('XSHELLZ_API_URL=https://api.staging.example/v1');

    $history = [];
    $handler = mockHandler([jsonResponse(200, [])], $history);

    expect(Sandbox::list(httpHandler: $handler))->toBe([]);

    $request = $history[0]['request'];
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer env-key')
        ->and($request->getUri()->getHost())->toBe('api.staging.example');
});

test('explicit arguments beat the environment', function (): void {
    putenv('XSHELLZ_API_KEY=env-key');
    putenv('XSHELLZ_API_URL=https://env-host.example/v1');

    $history = [];
    $handler = mockHandler([jsonResponse(200, [])], $history);

    Sandbox::list(apiKey: 'arg-key', apiUrl: 'https://arg-host.example/v1', httpHandler: $handler);

    $request = $history[0]['request'];
    expect($request->getHeaderLine('Authorization'))->toBe('Bearer arg-key')
        ->and($request->getUri()->getHost())->toBe('arg-host.example');
});

// --------------------------------------------------------------------- //
// Error mapping (guard chain -> typed exceptions)
// --------------------------------------------------------------------- //

function createFailingWith(int $status, mixed $body): Sandbox
{
    return Sandbox::create(apiKey: 'k', httpHandler: mockHandler([jsonResponse($status, $body)]));
}

test('401 maps to AuthException', function (): void {
    expect(fn (): Sandbox => createFailingWith(401, ['message' => 'Unauthenticated.']))
        ->toThrow(AuthException::class, '401');
});

test('403 quota limit maps to QuotaException', function (): void {
    expect(fn (): Sandbox => createFailingWith(
        403,
        ['message' => "You've reached your plan's agent shell limit (1)."],
    ))->toThrow(QuotaException::class, 'agent shell limit');
});

test('403 entitlement maps to QuotaException', function (): void {
    expect(fn (): Sandbox => createFailingWith(
        403,
        ['message' => 'Your plan does not include agent shells. Upgrade to add one.'],
    ))->toThrow(QuotaException::class, 'does not include agent shells');
});

test('403 verification_required maps to AuthException', function (): void {
    expect(fn (): Sandbox => createFailingWith(403, [
        'error' => 'verification_required',
        'message' => 'Verify your account with a card (free - nothing is charged) to create a shell.',
    ]))->toThrow(AuthException::class, 'verification');
});

test('403 limited preview maps to AuthException', function (): void {
    expect(fn (): Sandbox => createFailingWith(403, ['message' => 'Agent Shell is in limited preview.']))
        ->toThrow(AuthException::class, 'limited preview');
});

test('429 maps to ApiException carrying the status code', function (): void {
    try {
        createFailingWith(429, ['message' => 'Too Many Attempts.']);
        $this->fail('Expected ApiException.');
    } catch (ApiException $exception) {
        expect($exception->statusCode)->toBe(429)
            ->and($exception->getMessage())->toContain('10 requests/minute');
    }
});

test('503 maps to ApiException carrying status and body', function (): void {
    try {
        createFailingWith(503, ['message' => 'Agent shells are not available yet.']);
        $this->fail('Expected ApiException.');
    } catch (ApiException $exception) {
        expect($exception->statusCode)->toBe(503)
            ->and($exception->body)->toBe(['message' => 'Agent shells are not available yet.']);
    }
});

// --------------------------------------------------------------------- //
// list() / connect()
// --------------------------------------------------------------------- //

test('list parses the bare top-level array', function (): void {
    $payload = [
        shellPayload(),
        shellPayload(['uuid' => 'deadbeef', 'name' => 'second', 'status' => 'stopped']),
    ];

    $infos = Sandbox::list(apiKey: 'k', httpHandler: mockHandler([jsonResponse(200, $payload)]));

    expect($infos)->toHaveCount(2)
        ->and($infos[0]->uuid)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($infos[0]->gvisor)->toBeTrue()
        ->and($infos[1]->uuid)->toBe('deadbeef')
        ->and($infos[1]->status)->toBe('stopped');
});

test('connect finds the sandbox in the list', function (): void {
    $keyPair = KeyPair::generate();
    $history = [];
    $handler = mockHandler([jsonResponse(200, [shellPayload()])], $history);

    $sbx = Sandbox::connect(
        '11111111-2222-3333-4444-555555555555',
        privateKey: $keyPair->privateKeyOpenSsh,
        apiKey: 'k',
        httpHandler: $handler,
    );

    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('GET')
        ->and($request->getUri()->getPath())->toBe('/v1/shells/agent');

    expect($sbx->uuid)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($sbx->status)->toBe('running')
        ->and($sbx->privateKey)->not->toBeNull()
        ->and($sbx->privateKeyOpenSsh)->toBe($keyPair->privateKeyOpenSsh);
});

test('connect with an unknown uuid throws SandboxNotRunningException', function (): void {
    $keyPair = KeyPair::generate();

    expect(fn (): Sandbox => Sandbox::connect(
        'no-such-uuid',
        privateKey: $keyPair->privateKey,
        apiKey: 'k',
        httpHandler: mockHandler([jsonResponse(200, [])]),
    ))->toThrow(SandboxNotRunningException::class, 'not found');
});

// --------------------------------------------------------------------- //
// kill() / start()
// --------------------------------------------------------------------- //

/**
 * @param list<\GuzzleHttp\Psr7\Response> $responses
 * @param list<array{request: \Psr\Http\Message\RequestInterface}>|null $history
 */
function controlPlaneSandbox(array $responses, ?array &$history = null): Sandbox
{
    return new Sandbox(
        info: SandboxInfo::fromApi(shellPayload()),
        api: new ApiClient(apiKey: 'k', handler: mockHandler($responses, $history)),
    );
}

test('kill issues a DELETE and is idempotent', function (): void {
    $history = [];
    $sbx = controlPlaneSandbox([jsonResponse(200, ['deleted' => true])], $history);

    $sbx->kill();
    $sbx->kill(); // second call is a no-op

    expect($history)->toHaveCount(1);
    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('DELETE')
        ->and($request->getUri()->getPath())
        ->toBe('/v1/shells/agent/11111111-2222-3333-4444-555555555555');
});

test('kill swallows a 404 (already gone)', function (): void {
    $sbx = controlPlaneSandbox([jsonResponse(404, ['message' => 'Agent shell not found.'])]);

    $sbx->kill(); // must not throw

    expect(true)->toBeTrue();
});

test('kill rethrows non-404 API errors', function (): void {
    $sbx = controlPlaneSandbox([jsonResponse(500, ['message' => 'boom'])]);

    expect(fn () => $sbx->kill())->toThrow(ApiException::class);
});

test('start posts to /start and updates the info', function (): void {
    $history = [];
    $sbx = controlPlaneSandbox([jsonResponse(200, shellPayload(['status' => 'running']))], $history);

    $sbx->start();

    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())
        ->toBe('/v1/shells/agent/11111111-2222-3333-4444-555555555555/start')
        ->and($sbx->status)->toBe('running');
});

test("start's 404 maps to SandboxNotRunningException", function (): void {
    $sbx = controlPlaneSandbox([jsonResponse(404, ['message' => 'Stopped agent shell not found.'])]);

    expect(fn () => $sbx->start())->toThrow(SandboxNotRunningException::class);
});

test('refresh re-fetches state from the list endpoint', function (): void {
    $sbx = controlPlaneSandbox([jsonResponse(200, [shellPayload(['status' => 'stopped'])])]);

    $info = $sbx->refresh();

    expect($info->status)->toBe('stopped')
        ->and($sbx->status)->toBe('stopped');
});
