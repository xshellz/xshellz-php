<?php

declare(strict_types=1);

use Xshellz\ApiClient;
use Xshellz\CommandResult;
use Xshellz\Exceptions\XshellzException;
use Xshellz\Sandbox;
use Xshellz\SandboxInfo;
use Xshellz\Tests\Support\FakeTransport;

function runCodeSandbox(FakeTransport $fake): Sandbox
{
    return new Sandbox(
        info: SandboxInfo::fromApi(shellPayload()),
        api: new ApiClient(apiKey: 'k', handler: mockHandler([])),
        transport: $fake,
    );
}

test('runCode writes a temp file, runs the interpreter, and cleans up', function (): void {
    $fake = new FakeTransport;
    $fake->nextResult = new CommandResult(stdout: '42', stderr: '', exitCode: 0);

    $result = runCodeSandbox($fake)->runCode('python', 'print(6 * 7)');

    // The code was uploaded to a .py temp file...
    expect($fake->files)->toHaveCount(1);
    $path = array_key_first($fake->files);
    expect($path)->toMatch('#^/tmp/xshellz-code-[0-9a-f]{12}\.py$#')
        ->and($fake->files[$path])->toBe('print(6 * 7)');

    // ...executed with python3, and deleted whatever the exit code.
    expect($fake->commands)->toBe(["python3 {$path}; __xs_rc=\$?; rm -f {$path}; exit \$__xs_rc"])
        ->and($result->stdout)->toBe('42')
        ->and($result->ok())->toBeTrue();
});

test('runCode maps each language to its interpreter and extension', function (string $language, string $interpreter, string $extension): void {
    $fake = new FakeTransport;

    runCodeSandbox($fake)->runCode($language, 'code');

    $path = array_key_first($fake->files);
    expect($path)->toEndWith('.' . $extension)
        ->and($fake->commands[0])->toStartWith("{$interpreter} {$path};");
})->with([
    ['python', 'python3', 'py'],
    ['node', 'node', 'js'],
    ['bash', 'bash', 'sh'],
    ['ruby', 'ruby', 'rb'],
    ['php', 'php', 'php'],
]);

test('the language is case-insensitive', function (): void {
    $fake = new FakeTransport;

    runCodeSandbox($fake)->runCode('Python', 'x = 1');

    expect($fake->commands[0])->toStartWith('python3 ');
});

test('an unsupported language throws and lists the supported ones', function (): void {
    $sbx = runCodeSandbox(new FakeTransport);

    expect(fn (): CommandResult => $sbx->runCode('cobol', 'MOVE 1 TO X'))
        ->toThrow(XshellzException::class, 'python, node, bash, ruby, php');
});

test('runCode passes cwd and env through to run()', function (): void {
    $fake = new FakeTransport;

    runCodeSandbox($fake)->runCode('bash', 'echo "$MODE" "$(pwd)"', cwd: '/srv', env: ['MODE' => 'ci']);

    expect($fake->commands[0])->toStartWith('export MODE=ci && cd /srv && bash /tmp/xshellz-code-');
});

test('a non-zero exit from the code does not throw', function (): void {
    $fake = new FakeTransport;
    $fake->nextResult = new CommandResult(stdout: '', stderr: 'Traceback...', exitCode: 1);

    $result = runCodeSandbox($fake)->runCode('python', 'raise SystemExit(1)');

    expect($result->exitCode)->toBe(1)
        ->and($result->ok())->toBeFalse();
});
