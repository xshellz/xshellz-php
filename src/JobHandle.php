<?php

declare(strict_types=1);

namespace Xshellz;

/**
 * A background process started with Sandbox::spawn() (or listed by
 * Sandbox::jobs()).
 *
 * The process runs under nohup inside the sandbox, fully detached from the
 * SSH session that started it: it survives run()/close() and keeps running
 * until it exits, is stop()ped, or the box itself stops. Its combined
 * stdout+stderr goes to $logPath inside the box.
 */
final class JobHandle
{
    /**
     * @param string $id Job id (name-prefixed short random id).
     * @param int|null $pid Process id inside the sandbox (null when it could not be captured).
     * @param string $logPath Remote log file path (combined stdout+stderr), e.g. `~/.xshellz/jobs/<id>.log`.
     * @param bool|null $running Liveness snapshot taken when this handle came from
     *     Sandbox::jobs() (null for handles fresh from spawn()); isRunning() probes live.
     */
    public function __construct(
        private readonly Sandbox $sandbox,
        public readonly string $id,
        public readonly ?int $pid,
        public readonly string $logPath,
        public readonly ?bool $running = null,
    ) {
    }

    /**
     * Probe whether the process is still alive (`kill -0` inside the box).
     *
     * @throws Exceptions\SandboxNotRunningException The box is not running.
     */
    public function isRunning(): bool
    {
        if ($this->pid === null) {
            return false;
        }

        return $this->sandbox->run("kill -0 {$this->pid} 2>/dev/null")->ok();
    }

    /**
     * Return the last $tailLines lines of the job's log file (empty string
     * when the log does not exist yet).
     *
     * @throws Exceptions\SandboxNotRunningException The box is not running.
     */
    public function logs(int $tailLines = 100): string
    {
        return $this->sandbox
            ->run("tail -n {$tailLines} {$this->logPath} 2>/dev/null; true")
            ->stdout;
    }

    /**
     * Stop the process: SIGTERM, then SIGKILL if it is still alive after
     * $graceSeconds. Idempotent - stopping an already-dead job is a no-op.
     *
     * @throws Exceptions\SandboxNotRunningException The box is not running.
     */
    public function stop(int $graceSeconds = 5): void
    {
        if ($this->pid === null) {
            return;
        }

        $pid = $this->pid;
        $this->sandbox->run(
            "kill -TERM {$pid} 2>/dev/null; i=0; "
            . "while [ \$i -lt {$graceSeconds} ]; do "
            . "kill -0 {$pid} 2>/dev/null || exit 0; sleep 1; i=\$((i+1)); done; "
            . "kill -KILL {$pid} 2>/dev/null; true",
            timeoutSeconds: (float) ($graceSeconds + 30),
        );
    }
}
