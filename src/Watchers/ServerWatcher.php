<?php

declare(strict_types=1);

namespace Mata\Watchers;

use Mata\Services\Command\CommandRunnerInterface;
use Mata\Services\Command\NativeCommandRunner;
use Mata\Services\FileCache;

class ServerWatcher
{
    private string $rootPath;
    private FileCache $cache;
    private bool $isDiskSpaceCheckEnabled;
    private string $diskSpaceCheckPath;
    private int $filecountCacheMaxAge;
    private CommandRunnerInterface $commandRunner;

    public function __construct(string $rootPath, FileCache $cache, array $transmitConfig, ?CommandRunnerInterface $commandRunner = null)
    {
        $this->rootPath = $rootPath;
        $this->cache = $cache;
        $this->commandRunner = $commandRunner ?? new NativeCommandRunner();

        $this->diskSpaceCheckPath = $transmitConfig['check_disk_space_path'] ?? '/';
        $this->isDiskSpaceCheckEnabled = ($transmitConfig['check_disk_space_enabled'] ?? true) !== 'off';
        $this->filecountCacheMaxAge = $transmitConfig['system_filecount_cache_max_age'] ?? 1800;
    }

    public function getServerData(): array
    {

        $diskSpaceCapacity = 0;
        $diskSpaceUsed = 0;
        if ($this->isDiskSpaceCheckEnabled) {
            $diskStats = $this->getDiskStats($this->diskSpaceCheckPath);
            $diskSpaceCapacity = $diskStats['total'];
            $diskSpaceUsed = $diskStats['used'];
        }

        $filecount = $this->getSystemFilecount($this->filecountCacheMaxAge);

        $data = [
            'total_system_filecount' => $filecount,
            'ram_capacity' => (int) $this->commandRunner->run("grep MemTotal /proc/meminfo | awk '{print $2}'"), // in bytes
            'ram_used' => (int) $this->commandRunner->run("grep MemAvailable /proc/meminfo | awk '{print $2}'"),
            'disk_space_capacity' => $diskSpaceCapacity, // in bytes
            'disk_space_used' => $diskSpaceUsed,
            'cpu_usage' => trim($this->commandRunner->run("top -bn1 | grep 'Cpu(s)' | awk '{print 100 - $8}'") ?? ''), // % used
            'processes' => [
                'total' => (int) $this->commandRunner->run('ps -e --no-headers | wc -l'),
                'php' => $this->commandRunner->countProcesses('php', exact: false),
                'sql' => $this->commandRunner->countProcesses(DatabaseWatcher::MYSQL_PROCESS_NAMES),
            ],
        ];

        $data['ram_used'] = $data['ram_capacity'] - $data['ram_used'];

        return $data;
    }

    public function getServerInfo(): array
    {
        $osRelease = $this->readOsRelease();
        $phpExtensions = get_loaded_extensions();
        sort($phpExtensions);

        return [
            'os_name' => $osRelease['NAME'] ?? php_uname('s') ?: '',
            'os_version' => $osRelease['VERSION_ID'] ?? $osRelease['VERSION'] ?? '',
            'kernel_version' => php_uname('r') ?: '',
            'package_count' => $this->getPackageCount(),
            'php_version' => PHP_VERSION,
            'php_extension_count' => count($phpExtensions),
            'php_extensions' => $phpExtensions,
            'architecture' => php_uname('m') ?: '',
            'hostname' => gethostname() ?: '',
        ];
    }

    public function getSystemFilecount($maxAgeCache): int
    {
        return (int) $this->cache->remember('server/filecount', (int) $maxAgeCache, function (): int {
            return (int) $this->commandRunner->run('du -a ' . escapeshellarg($this->rootPath) . ' | wc -l');
        });
    }

    public function getPhpProcesses(): array
    {
        // Get all running PHP processes
        $rawOutput = $this->commandRunner->run('ps -eo user,pid,ppid,%cpu,%mem,etime,args | grep "[p]hp"');
        if (!$rawOutput) {
            return [];
        }

        $lines = explode("\n", trim($rawOutput));

        $data = [];
        foreach ($lines as $line) {
            // Normalize whitespace and split into fields
            $parts = preg_split('/\s+/', $line, 7); // 7 parts: user, pid, ppid, cpu, mem, etime, args
            if (count($parts) === 7) {
                $data[] = [
                    'user' => $parts[0],
                    'pid' => (int) $parts[1],
                    'ppid' => (int) $parts[2],
                    'cpu' => (float) $parts[3],
                    'mem' => (float) $parts[4],
                    'etime' => $parts[5],
                    'args' => $parts[6],
                ];
            }
        }

        return $data;
    }

    private function readOsRelease(): array
    {
        $file = '/etc/os-release';
        if (!is_readable($file)) {
            return [];
        }

        $values = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = trim($value, " \t\n\r\0\x0B\"");
        }

        return $values;
    }

    private function getPackageCount(): int
    {
        $commands = [
            'dpkg-query -f \'.\' -W 2>/dev/null | wc -c',
            'rpm -qa 2>/dev/null | wc -l',
            'pacman -Q 2>/dev/null | wc -l',
        ];

        foreach ($commands as $command) {
            $output = $this->commandRunner->run($command) ?? '';
            if ($output === '') {
                continue;
            }

            $count = (int) trim($output);
            if ($count > 0) {
                return $count;
            }
        }

        return 0;
    }

    private function getDiskStats($path = '/')
    {
        $total = @disk_total_space($path) / 1024;
        $free = @disk_free_space($path) / 1024;

        // Fallback if PHP functions return 0
        if (!$total || !$free) {
            $output = $this->commandRunner->run('df -k ' . escapeshellarg($path) . ' 2>/dev/null');
            if ($output) {
                $lines = explode("\n", trim($output));
                if (count($lines) > 1) {
                    $parts = preg_split('/\s+/', $lines[1]);
                    if (count($parts) >= 5) {
                        $total = (int) $parts[1];
                        $free = (int) $parts[3];
                    }
                }
            }
        }

        $used = $total - $free;

        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
        ];
    }
}
