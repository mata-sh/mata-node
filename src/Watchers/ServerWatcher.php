<?php

declare(strict_types=1);

namespace Mata\Watchers;

class ServerWatcher
{
    private string $rootPath;
    private string $cachePath;
    private bool $isDiskSpaceCheckEnabled;
    private string $diskSpaceCheckPath;
    private int $filecountCacheMaxAge;

    public function __construct(string $rootPath, string $cachePath, array $transmitConfig)
    {
        $this->rootPath = $rootPath;
        $this->cachePath = $cachePath;

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
            'ram_capacity' => (int) shell_exec("grep MemTotal /proc/meminfo | awk '{print $2}'"), // in bytes
            'ram_used' => (int) shell_exec("grep MemAvailable /proc/meminfo | awk '{print $2}'"),
            'disk_space_capacity' => $diskSpaceCapacity, // in bytes
            'disk_space_used' => $diskSpaceUsed,
            'cpu_usage' => trim(shell_exec("top -bn1 | grep 'Cpu(s)' | awk '{print 100 - $8}'")), // % used
            'processes' => [
                'total' => (int) shell_exec('ps -e --no-headers | wc -l'),
                'php' => (int) shell_exec('pgrep -c php'),
                'sql' => (int) shell_exec('pgrep -c sql'),
            ],
        ];

        $data['ram_used'] = $data['ram_capacity'] - $data['ram_used'];

        // error_log(print_r($data,true));
        return $data;
    }

    public function getSystemFilecount($maxAgeCache): int
    {

        $cacheFile = $this->cachePath . '/filecount.cache';
        if (file_exists($cacheFile)) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge > $maxAgeCache) {
                return (int) file_get_contents($cacheFile);
            }
        }

        $count = (int) shell_exec('du -a ' . escapeshellarg($this->rootPath) . ' | wc -l');
        file_put_contents($cacheFile, $count);

        return $count;
    }

    public function getPhpProcesses(): array
    {
        // Get all running PHP processes
        $rawOutput = shell_exec('ps -eo user,pid,ppid,%cpu,%mem,etime,args | grep "[p]hp"');
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

    private function getDiskStats($path = '/')
    {
        $total = @disk_total_space($path) / 1024;
        $free = @disk_free_space($path) / 1024;

        // Fallback if PHP functions return 0
        if (!$total || !$free) {
            $output = @shell_exec('df -k ' . escapeshellarg($path) . ' 2>/dev/null');
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
