<?php

declare(strict_types=1);

use Xshellz\ApiClient;
use Xshellz\CommandResult;
use Xshellz\Exceptions\SandboxNotRunningException;
use Xshellz\Exceptions\XshellzException;
use Xshellz\Sandbox;
use Xshellz\SandboxInfo;
use Xshellz\Ssh\ShellCommand;
use Xshellz\Tests\Support\FakeTransport;

/**
 * @param list<\GuzzleHttp\Psr7\Response>|null $responses
 * @param list<array{request: \Psr\Http\Message\RequestInterface}>|null $history
 */
function makeSandbox(
    ?FakeTransport $fake,
    string $status = 'running',
    ?array $responses = null,
    ?array &$history = null,
): Sandbox {
    $responses ??= [jsonResponse(200, ['deleted' => true])];

    return new Sandbox(
        info: SandboxInfo::fromApi(shellPayload(['status' => $status])),
        api: new ApiClient(apiKey: 'k', handler: mockHandler($responses, $history)),
        transport: $fake,
    );
}

test('run returns a CommandResult and a non-zero exit does not throw', function (): void {
    $fake = new FakeTransport;
    $fake->nextResult = new CommandResult(stdout: '', stderr: 'boom', exitCode: 2);
    $sbx = makeSandbox($fake);

    $result = $sbx->run('false');

    expect($result->exitCode)->toBe(2)
        ->and($result->stderr)->toBe('boom')
        ->and($result->ok())->toBeFalse()
        ->and($fake->commands)->toBe(['false']);
});

test('run wraps cwd and env', function (): void {
    $fake = new FakeTransport;
    $sbx = makeSandbox($fake);

    $sbx->run('make build', cwd: '/srv/app dir', env: ['FOO' => 'a b', 'BAR' => '1']);

    expect($fake->commands)->toBe(["export FOO='a b' BAR=1 && cd '/srv/app dir' && make build"]);
});

test('run rejects invalid environment variable names', function (): void {
    $sbx = makeSandbox(new FakeTransport);

    expect(fn (): CommandResult => $sbx->run('true', env: ['BAD-NAME' => 'x']))
        ->toThrow(XshellzException::class, 'environment variable name');
});

test('run streams chunks to the callbacks', function (): void {
    $fake = new FakeTransport;
    $fake->streamChunks = [
        ['stdout', "line1\n"],
        ['stderr', "warn\n"],
        ['stdout', "line2\n"],
    ];
    $fake->nextResult = new CommandResult(stdout: "line1\nline2\n", stderr: "warn\n", exitCode: 0);
    $sbx = makeSandbox($fake);

    $out = [];
    $err = [];
    $result = $sbx->run(
        'build',
        onStdout: function (string $chunk) use (&$out): void {
            $out[] = $chunk;
        },
        onStderr: function (string $chunk) use (&$err): void {
            $err[] = $chunk;
        },
    );

    expect($out)->toBe(["line1\n", "line2\n"])
        ->and($err)->toBe(["warn\n"])
        ->and($result->stdout)->toBe("line1\nline2\n");
});

test('file round-trip plus upload and download', function (): void {
    $fake = new FakeTransport;
    $sbx = makeSandbox($fake);

    $sbx->writeFile('/tmp/a.bin', "\x00\x01data");
    expect($sbx->readFile('/tmp/a.bin'))->toBe("\x00\x01data");

    $local = tempnam(sys_get_temp_dir(), 'xshellz-test-');
    file_put_contents($local, 'hello');
    $sbx->upload($local, '/tmp/remote.txt');
    expect($fake->files['/tmp/remote.txt'])->toBe('hello');

    $out = tempnam(sys_get_temp_dir(), 'xshellz-test-');
    $sbx->download('/tmp/remote.txt', $out);
    expect(file_get_contents($out))->toBe('hello');

    unlink($local);
    unlink($out);
});

test('run on a non-running sandbox throws SandboxNotRunningException', function (): void {
    $sbx = makeSandbox(null, status: 'stopped');

    expect(fn (): CommandResult => $sbx->run('true'))
        ->toThrow(SandboxNotRunningException::class, 'stopped');
});

test('run without a private key throws', function (): void {
    $sbx = makeSandbox(null, status: 'running'); // no transport, no key

    expect(fn (): CommandResult => $sbx->run('true'))
        ->toThrow(XshellzException::class, 'private key');
});

test('kill closes the transport and deletes the box', function (): void {
    $history = [];
    $fake = new FakeTransport;
    $sbx = makeSandbox($fake, history: $history);

    $sbx->run('true');
    $sbx->kill();

    expect($fake->closed)->toBeTrue()
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('DELETE')
        ->and($history[0]['request']->getUri()->getPath())
        ->toBe('/v1/shells/agent/11111111-2222-3333-4444-555555555555');
});

test('detach makes kill a no-op so shared teardown keeps the box', function (): void {
    $history = [];
    $fake = new FakeTransport;
    $sbx = makeSandbox($fake, history: $history);

    $sbx->detach();
    $sbx->kill();

    expect($history)->toHaveCount(0) // no DELETE issued
        ->and($fake->closed)->toBeTrue(); // SSH still closed; box kept alive
});

test('start closes the stale transport', function (): void {
    $fake = new FakeTransport;
    $sbx = makeSandbox($fake, responses: [jsonResponse(200, shellPayload())]);

    $sbx->start();

    expect($fake->closed)->toBeTrue();
});

test('ShellCommand::build plain and with cwd', function (): void {
    expect(ShellCommand::build('echo hi'))->toBe('echo hi')
        ->and(ShellCommand::build('echo hi', cwd: '/tmp'))->toBe('cd /tmp && echo hi');
});

test('ShellCommand::quote single-quotes unsafe values and escapes embedded quotes', function (): void {
    expect(ShellCommand::quote('simple/path-1.txt'))->toBe('simple/path-1.txt')
        ->and(ShellCommand::quote('a b'))->toBe("'a b'")
        ->and(ShellCommand::quote("it's"))->toBe("'it'\\''s'")
        ->and(ShellCommand::quote(''))->toBe("''");
});
