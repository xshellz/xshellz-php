<?php

declare(strict_types=1);

namespace Xshellz;

/**
 * A sandbox as reported by the control plane (snake_case wire shape).
 */
final readonly class SandboxInfo
{
    public function __construct(
        public string $uuid,
        public string $name,
        public string $status,
        public ?string $sshCommand,
        public ?string $sshHost,
        public ?int $sshPort,
        public bool $webTerminalReady,
        public bool $alwaysOn,
        public float $trialHoursRemaining,
        public ?string $spawnedAt,
        public ?string $createdAt,
        public ?string $isolation,
        public bool $gvisor,
    ) {
    }

    /**
     * Build a SandboxInfo from the API's JSON payload.
     *
     * Tolerant of missing keys so a newer/older API version never breaks
     * deserialization.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromApi(array $payload): self
    {
        $port = $payload['ssh_port'] ?? null;

        return new self(
            uuid: (string) ($payload['uuid'] ?? ''),
            name: (string) ($payload['name'] ?? ''),
            status: (string) ($payload['status'] ?? ''),
            sshCommand: isset($payload['ssh_command']) ? (string) $payload['ssh_command'] : null,
            sshHost: isset($payload['ssh_host']) ? (string) $payload['ssh_host'] : null,
            sshPort: is_numeric($port) ? (int) $port : null,
            webTerminalReady: (bool) ($payload['web_terminal_ready'] ?? false),
            alwaysOn: (bool) ($payload['always_on'] ?? false),
            trialHoursRemaining: is_numeric($payload['trial_hours_remaining'] ?? null)
                ? (float) $payload['trial_hours_remaining']
                : 0.0,
            spawnedAt: isset($payload['spawned_at']) ? (string) $payload['spawned_at'] : null,
            createdAt: isset($payload['created_at']) ? (string) $payload['created_at'] : null,
            isolation: isset($payload['isolation']) ? (string) $payload['isolation'] : null,
            gvisor: (bool) ($payload['gvisor'] ?? false),
        );
    }
}
