<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/*
 * Tests must never pick up a developer's real credentials or URL.
 */
uses()->beforeEach(function (): void {
    putenv('XSHELLZ_API_KEY');
    putenv('XSHELLZ_API_URL');
})->in(__DIR__);

/**
 * A canonical AgentShellResponse wire payload (snake_case).
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function shellPayload(array $overrides = []): array
{
    return array_merge([
        'uuid' => '11111111-2222-3333-4444-555555555555',
        'name' => 'agent-shell',
        'status' => 'running',
        'ssh_command' => 'ssh -p 42001 root@shellus1.xshellz.com',
        'ssh_host' => 'shellus1.xshellz.com',
        'ssh_port' => 42001,
        'web_terminal_ready' => true,
        'trial_ends_at' => null,
        'always_on' => true,
        'trial_hours_remaining' => 0.0,
        'spawned_at' => '2026-07-16T12:00:00+00:00',
        'created_at' => '2026-07-16T12:00:00+00:00',
        'isolation' => 'runsc',
        'gvisor' => true,
    ], $overrides);
}

function jsonResponse(int $status, mixed $body): Response
{
    return new Response(
        $status,
        ['Content-Type' => 'application/json'],
        (string) json_encode($body),
    );
}

/**
 * Build a Guzzle handler stack over queued mock responses, optionally
 * recording the request/response history into $history.
 *
 * @param list<Response> $responses
 * @param list<array{request: \Psr\Http\Message\RequestInterface, response: ?Response}>|null $history
 */
function mockHandler(array $responses, ?array &$history = null): HandlerStack
{
    $stack = HandlerStack::create(new MockHandler($responses));
    if ($history !== null) {
        $stack->push(Middleware::history($history));
    }

    return $stack;
}
