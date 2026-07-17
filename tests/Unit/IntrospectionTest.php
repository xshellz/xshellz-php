<?php

declare(strict_types=1);

use Xshellz\ApiClient;
use Xshellz\Exceptions\SandboxNotRunningException;
use Xshellz\Sandbox;
use Xshellz\SandboxInfo;
use Xshellz\Tests\Support\FakeTransport;

/**
 * @param list<\GuzzleHttp\Psr7\Response> $responses
 * @param list<array{request: \Psr\Http\Message\RequestInterface}>|null $history
 */
function introspectionSandbox(array $responses, ?array &$history = null, ?FakeTransport $fake = null): Sandbox
{
    return new Sandbox(
        info: SandboxInfo::fromApi(shellPayload()),
        api: new ApiClient(apiKey: 'k', handler: mockHandler($responses, $history)),
        transport: $fake,
    );
}

const SHELL_PATH = '/v1/shells/agent/11111111-2222-3333-4444-555555555555';

// --------------------------------------------------------------------- //
// stats() / procs()
// --------------------------------------------------------------------- //

test('stats fetches the live wire fields as-is', function (): void {
    $wire = [
        'mem_used_mb' => 123, 'mem_limit_mb' => 1024, 'mem_allowed_mb' => 1024,
        'cpu_percent' => 12.5, 'cpu_allowed_vcpus' => 1.5, 'cpu_throttled_periods' => 0,
        'pids_current' => 17, 'pids_allowed' => 256,
        'disk_used_mb' => 800, 'disk_allowed_mb' => 5120,
        'net_rx_mb' => 1.2, 'net_tx_mb' => 0.4, 'blk_read_mb' => 10, 'blk_write_mb' => 3,
    ];
    $history = [];
    $sbx = introspectionSandbox([jsonResponse(200, $wire)], $history);

    expect($sbx->stats())->toBe($wire);

    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('GET')
        ->and($request->getUri()->getPath())->toBe(SHELL_PATH . '/stats');
});

test('procs fetches processes, sessions, and disk as-is', function (): void {
    $wire = [
        'procs' => [['pid' => 1, 'comm' => 'bash', 'cpu' => 0.1, 'mem' => 0.5]],
        'sessions' => 2,
        'agents' => ['claude'],
        'disk_used_mb' => 800,
        'disk_allowed_mb' => 5120,
    ];
    $history = [];
    $sbx = introspectionSandbox([jsonResponse(200, $wire)], $history);

    expect($sbx->procs())->toBe($wire)
        ->and($history[0]['request']->getUri()->getPath())->toBe(SHELL_PATH . '/procs');
});

// --------------------------------------------------------------------- //
// restart()
// --------------------------------------------------------------------- //

test('restart posts to /restart, refreshes info, and drops the SSH transport', function (): void {
    $history = [];
    $fake = new FakeTransport;
    $sbx = introspectionSandbox([jsonResponse(200, shellPayload(['status' => 'running']))], $history, $fake);

    $sbx->restart();

    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe(SHELL_PATH . '/restart')
        ->and($sbx->status)->toBe('running')
        ->and($fake->closed)->toBeTrue();
});

test("restart's 404 maps to SandboxNotRunningException", function (): void {
    $sbx = introspectionSandbox([jsonResponse(404, ['message' => 'Agent shell not found or not running.'])]);

    expect(fn () => $sbx->restart())->toThrow(SandboxNotRunningException::class);
});

// --------------------------------------------------------------------- //
// terminalUrl()
// --------------------------------------------------------------------- //

test('terminalUrl returns the freshly minted signed URL', function (): void {
    $history = [];
    $sbx = introspectionSandbox(
        [jsonResponse(200, ['url' => 'https://shellus1.xshellz.com/terminal/abc?t=123.deadbeef'])],
        $history,
    );

    expect($sbx->terminalUrl())->toBe('https://shellus1.xshellz.com/terminal/abc?t=123.deadbeef')
        ->and($history[0]['request']->getUri()->getPath())->toBe(SHELL_PATH . '/terminal');
});

// --------------------------------------------------------------------- //
// Boxfile (account-level template)
// --------------------------------------------------------------------- //

test('getBoxfile reads the saved manifest', function (): void {
    $history = [];
    $manifest = Sandbox::getBoxfile(
        apiKey: 'k',
        httpHandler: mockHandler([jsonResponse(200, ['manifest' => "jq\nripgrep"])], $history),
    );

    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('GET')
        ->and($request->getUri()->getPath())->toBe('/v1/shells/agent/boxfile')
        ->and($manifest)->toBe("jq\nripgrep");
});

test('getBoxfile returns null when nothing is saved', function (): void {
    $manifest = Sandbox::getBoxfile(
        apiKey: 'k',
        httpHandler: mockHandler([jsonResponse(200, ['manifest' => null])]),
    );

    expect($manifest)->toBeNull();
});

test('setBoxfile PUTs the manifest and returns it as stored', function (): void {
    $history = [];
    $manifest = Sandbox::setBoxfile(
        "jq\nripgrep",
        apiKey: 'k',
        httpHandler: mockHandler([jsonResponse(200, ['manifest' => "jq\nripgrep"])], $history),
    );

    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('PUT')
        ->and($request->getUri()->getPath())->toBe('/v1/shells/agent/boxfile')
        ->and(json_decode((string) $request->getBody(), true))->toBe(['manifest' => "jq\nripgrep"])
        ->and($manifest)->toBe("jq\nripgrep");
});

test('setBoxfile with null clears the manifest', function (): void {
    $history = [];
    $manifest = Sandbox::setBoxfile(
        null,
        apiKey: 'k',
        httpHandler: mockHandler([jsonResponse(200, ['manifest' => null])], $history),
    );

    expect(json_decode((string) $history[0]['request']->getBody(), true))->toBe(['manifest' => null])
        ->and($manifest)->toBeNull();
});
