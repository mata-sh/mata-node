<?php

declare(strict_types=1);

namespace Mata\Watchers\Server;

use Mata\Services\Command\CommandRunnerInterface;
use Mata\Services\Command\NativeCommandRunner;
use Mata\Services\Command\ShellCommands;

final class CronjobInspector
{
    private const DEFAULT_STALE_SECONDS = 86400;
    private const DEFAULT_TAIL_LINE_LIMIT = 50;

    private CommandRunnerInterface $commandRunner;

    public function __construct(
        private readonly string $rootPath,
        private readonly array $transmitConfig,
        ?CommandRunnerInterface $commandRunner = null,
    ) {
        $this->commandRunner = $commandRunner ?? new NativeCommandRunner();
    }

    /**
     * @return array{total: int, working: int, stale: int, missing: int}
     */
    public function summary(): array
    {
        $summary = [
            'total' => 0,
            'working' => 0,
            'stale' => 0,
            'missing' => 0,
        ];

        foreach ($this->entries(includeContent: false) as $entry) {
            ++$summary['total'];
            if (!$entry['exists']) {
                ++$summary['missing'];

                continue;
            }

            if ($entry['stale']) {
                ++$summary['stale'];

                continue;
            }

            ++$summary['working'];
        }

        return $summary;
    }

    /**
     * @return array<int, array{index: int, path: string, exists: bool, mtime: ?string, age_seconds: ?int, size: int, stale: bool, content_tail?: array<int, string>}>
     */
    public function entries(bool $includeContent = false): array
    {
        $entries = [];
        $maxAgeSeconds = $this->intConfig('cronjob_heartbeat_stale_seconds', self::DEFAULT_STALE_SECONDS);

        foreach ($this->heartbeatPaths() as $index => $heartbeatPath) {
            $file = $this->rootPath . DIRECTORY_SEPARATOR . $heartbeatPath;
            $exists = is_readable($file) && is_file($file);
            $mtime = $exists ? filemtime($file) : false;
            $ageSeconds = $mtime === false ? null : max(0, time() - $mtime);
            $size = $exists ? filesize($file) : false;
            $entry = [
                'index' => $index,
                'path' => $heartbeatPath,
                'exists' => $exists,
                'mtime' => $mtime === false ? null : date('Y-m-d H:i:s', $mtime),
                'age_seconds' => $ageSeconds,
                'size' => $size === false ? 0 : (int) $size,
                'stale' => $exists && $ageSeconds !== null && $ageSeconds > $maxAgeSeconds,
            ];

            if ($includeContent) {
                $entry['content_tail'] = $exists ? $this->tailLines($file) : [];
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function heartbeatPaths(): array
    {
        $configured = $this->transmitConfig['cronjob_heartbeats'] ?? [];
        if (is_string($configured)) {
            return $configured === '' ? [] : [$configured];
        }

        if (!is_array($configured)) {
            return [];
        }

        $paths = [];
        foreach ($configured as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function intConfig(string $key, int $default): int
    {
        $configured = $this->transmitConfig[$key] ?? $default;

        return is_numeric($configured) ? max(1, (int) $configured) : $default;
    }

    /**
     * @return array<int,string>
     */
    private function tailLines(string $file): array
    {
        $output = $this->commandRunner->run(
            ShellCommands::tail($file, $this->intConfig('cronjob_heartbeat_tail_line_limit', self::DEFAULT_TAIL_LINE_LIMIT))
        );

        return $output !== null && trim($output) !== '' ? explode("\n", trim($output)) : [];
    }
}
