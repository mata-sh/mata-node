<?php

declare(strict_types=1);

namespace Mata\Watchers\Database;

/**
 * Engine-neutral, instance-wide connection status.
 *
 * Fields an engine cannot provide are null, never zero.
 */
final class OverviewStatus
{
    public function __construct(
        public readonly int $maxConnections,
        public readonly int $connections,
        public readonly int $running,
        public readonly ?int $maxUsedConnections,
        public readonly ?int $refusedConnections,
        public readonly ?int $abortedConnects,
        public readonly int $uptimeSeconds,
    ) {}

    /**
     * @return array<string, null|int>
     */
    public function toArray(): array
    {
        return [
            'max_connections' => $this->maxConnections,
            'connections' => $this->connections,
            'running' => $this->running,
            'max_used_connections' => $this->maxUsedConnections,
            'refused_connections' => $this->refusedConnections,
            'aborted_connects' => $this->abortedConnects,
            'uptime_seconds' => $this->uptimeSeconds,
        ];
    }
}
