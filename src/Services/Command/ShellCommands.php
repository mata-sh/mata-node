<?php

declare(strict_types=1);

namespace Mata\Services\Command;

final class ShellCommands
{
    private const MAX_LOG_LINES = 1_000_000;

    private function __construct() {}

    public static function head(string $file, int $lines): string
    {
        return 'head -n ' . ShellInput::positiveInt($lines, max: self::MAX_LOG_LINES) . ' -- ' . self::argPath($file);
    }

    public static function tail(string $file, int $lines): string
    {
        return 'tail -n ' . ShellInput::positiveInt($lines, max: self::MAX_LOG_LINES) . ' -- ' . self::argPath($file);
    }

    public static function lineCount(string $file): string
    {
        return 'wc -l < ' . self::argPath($file);
    }

    public static function composerAudit(
        ?string $binPath,
        string $workingDir,
        ?string $composerHome = null,
        ?string $phpBinary = null,
    ): string {
        $environment = $composerHome === null ? '' : 'COMPOSER_HOME=' . self::argPath($composerHome) . ' ';

        return $environment
            . self::composerCommand($binPath, $phpBinary)
            . ' --no-plugins --no-scripts audit --format=json --no-ansi --no-interaction --working-dir='
            . self::argPath($workingDir);
    }

    public static function processCount(string $pattern, bool $exact): string
    {
        $flags = $exact ? '-cx' : '-c';

        return 'pgrep ' . $flags . ' ' . escapeshellarg(ShellInput::safeProcessPattern($pattern)) . ' 2>/dev/null';
    }

    public static function memoryTotalKb(): string
    {
        return "grep MemTotal /proc/meminfo | awk '{print $2}'";
    }

    public static function memoryAvailableKb(): string
    {
        return "grep MemAvailable /proc/meminfo | awk '{print $2}'";
    }

    public static function cpuUsagePercent(): string
    {
        return "top -bn1 | grep 'Cpu(s)' | awk '{print 100 - $8}'";
    }

    public static function totalProcessCount(): string
    {
        return 'ps -e --no-headers | wc -l';
    }

    public static function phpProcesses(): string
    {
        return 'ps -eo user,pid,ppid,%cpu,%mem,etime,args | grep "[p]hp"';
    }

    public static function fileCount(string $path): string
    {
        return 'du -a -- ' . self::argPath($path) . ' | wc -l';
    }

    public static function diskFree(string $path): string
    {
        return 'df -k -- ' . self::argPath($path) . ' 2>/dev/null';
    }

    /**
     * @return list<string>
     */
    public static function packageCountCommands(): array
    {
        return [
            'dpkg-query -f \'.\' -W 2>/dev/null | wc -c',
            'rpm -qa 2>/dev/null | wc -l',
            'pacman -Q 2>/dev/null | wc -l',
        ];
    }

    private static function composerCommand(?string $binPath, ?string $phpBinary): string
    {
        if (!$binPath) {
            return 'composer';
        }

        $binPath = ShellInput::path($binPath);
        if (str_ends_with(strtolower($binPath), '.phar')) {
            $php = $phpBinary === null ? 'php' : escapeshellarg(ShellInput::path($phpBinary));

            return $php . ' ' . escapeshellarg($binPath);
        }

        return escapeshellarg($binPath);
    }

    private static function argPath(string $path): string
    {
        return escapeshellarg(ShellInput::path($path));
    }
}
