<?php

declare(strict_types=1);

use Xshellz\ApiClient;
use Xshellz\CommandResult;
use Xshellz\JobHandle;
use Xshellz\Sandbox;
use Xshellz\SandboxInfo;
use Xshellz\Tests\Support\FakeTransport;

function jobsSandbox(FakeTransport $fake): Sandbox
{
    return new Sandbox(
        info: SandboxInfo::fromApi(shellPayload()),
        api: new ApiClient(apiKey: 'k', handler: mockHandler([])),
        transport: $fake,
    );
}

// --------------------------------------------------------------------- //
// spawn()
// --------------------------------------------------------------------- //

test('spawn nohups the command into a job log and captures the pid', function (): void {
    $fake = new FakeTransport;
    $fake->nextResult = new CommandResult(stdout: "4242\n", stderr: '', exitCode: 0);

    $job = jobsSandbox($fake)->spawn('sleep 60');

    expect($fake->commands)->toHaveCount(1);
    $command = $fake->commands[0];
    expect($command)->toStartWith('mkdir -p ~/.xshellz/jobs; nohup bash -c ')
        ->and($command)->toContain("nohup bash -c 'sleep 60' > ~/.xshellz/jobs/{$job->id}.log 2>&1 < /dev/null & ")
        ->and($command)->toContain("echo \$! > ~/.xshellz/jobs/{$job->id}.pid; cat ~/.xshellz/jobs/{$job->id}.pid");

    expect($job->id)->toMatch('/^[0-9a-f]{8}$/')
        ->and($job->pid)->toBe(4242)
        ->and($job->logPath)->toBe("~/.xshellz/jobs/{$job->id}.log")
        ->and($job->running)->toBeNull(); // liveness snapshots come from jobs()
});

test('spawn prefixes the job id with the sanitized name', function (): void {
    $fake = new FakeTransport;
    $fake->nextResult = new CommandResult(stdout: "7\n", stderr: '', exitCode: 0);

    $job = jobsSandbox($fake)->spawn('true', name: 'My Worker!');

    expect($job->id)->toMatch('/^My-Worker-[0-9a-f]{8}$/');
});

test('spawn yields a null pid when the output is not numeric', function (): void {
    $fake = new FakeTransport;
    $fake->nextResult = new CommandResult(stdout: '', stderr: 'boom', exitCode: 1);

    $job = jobsSandbox($fake)->spawn('true');

    expect($job->pid)->toBeNull()
        ->and($job->isRunning())->toBeFalse(); // no pid -> not running, no probe
});

// --------------------------------------------------------------------- //
// JobHandle
// --------------------------------------------------------------------- //

function spawnedJob(FakeTransport $fake, int $pid = 4242): JobHandle
{
    $fake->queuedResults[] = new CommandResult(stdout: "{$pid}\n", stderr: '', exitCode: 0);

    return jobsSandbox($fake)->spawn('sleep 60');
}

test('isRunning probes the pid with kill -0', function (): void {
    $fake = new FakeTransport;
    $job = spawnedJob($fake);

    $fake->nextResult = new CommandResult(stdout: '', stderr: '', exitCode: 0);
    expect($job->isRunning())->toBeTrue();

    $fake->nextResult = new CommandResult(stdout: '', stderr: '', exitCode: 1);
    expect($job->isRunning())->toBeFalse();

    expect($fake->commands[1])->toBe('kill -0 4242 2>/dev/null');
});

test('logs tails the job log file', function (): void {
    $fake = new FakeTransport;
    $job = spawnedJob($fake);

    $fake->nextResult = new CommandResult(stdout: "line1\nline2\n", stderr: '', exitCode: 0);

    expect($job->logs())->toBe("line1\nline2\n")
        ->and($fake->commands[1])->toBe("tail -n 100 {$job->logPath} 2>/dev/null; true");

    $job->logs(tailLines: 5);
    expect($fake->commands[2])->toStartWith('tail -n 5 ');
});

test('stop sends SIGTERM then escalates to SIGKILL after the grace window', function (): void {
    $fake = new FakeTransport;
    $job = spawnedJob($fake);

    $job->stop(graceSeconds: 3);

    $command = $fake->commands[1];
    expect($command)->toStartWith('kill -TERM 4242 2>/dev/null; ')
        ->and($command)->toContain('while [ $i -lt 3 ]')
        ->and($command)->toContain('kill -0 4242 2>/dev/null || exit 0')
        ->and($command)->toContain('kill -KILL 4242 2>/dev/null');
});

test('stop without a pid is a no-op', function (): void {
    $fake = new FakeTransport;
    $fake->nextResult = new CommandResult(stdout: '', stderr: '', exitCode: 1);
    $job = jobsSandbox($fake)->spawn('true'); // pid unparsable -> null

    $job->stop();

    expect($fake->commands)->toHaveCount(1); // only the spawn itself
});

// --------------------------------------------------------------------- //
// jobs()
// --------------------------------------------------------------------- //

test('jobs lists log files with a liveness snapshot', function (): void {
    $fake = new FakeTransport;
    $fake->nextResult = new CommandResult(
        stdout: "abcd1234 111 1\nworker-ef567890 - 0\n",
        stderr: '',
        exitCode: 0,
    );

    $jobs = jobsSandbox($fake)->jobs();

    expect($fake->commands[0])->toContain('for f in *.log')
        ->and($jobs)->toHaveCount(2);

    expect($jobs[0]->id)->toBe('abcd1234')
        ->and($jobs[0]->pid)->toBe(111)
        ->and($jobs[0]->running)->toBeTrue()
        ->and($jobs[0]->logPath)->toBe('~/.xshellz/jobs/abcd1234.log');

    expect($jobs[1]->id)->toBe('worker-ef567890')
        ->and($jobs[1]->pid)->toBeNull() // pid file was lost
        ->and($jobs[1]->running)->toBeFalse();
});

test('jobs returns an empty list when the jobs directory is empty', function (): void {
    $fake = new FakeTransport;
    $fake->nextResult = new CommandResult(stdout: '', stderr: '', exitCode: 0);

    expect(jobsSandbox($fake)->jobs())->toBe([]);
});
