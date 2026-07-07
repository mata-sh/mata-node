<?php

declare(strict_types=1);

namespace Mata\Services;

use Throwable;

class NativeShellRunner implements ShellRunnerInterface
{
    public function run(string $command): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        try {
            $output = shell_exec($command);
        } catch (Throwable) {
            return null;
        }

        return is_string($output) ? $output : null;
    }

    public function countProcesses(string $namePattern, bool $exact = true): int
    {
        $flags = $exact ? '-cx' : '-c';

        return (int) $this->run('pgrep ' . $flags . ' ' . escapeshellarg($namePattern) . ' 2>/dev/null');
    }
}
