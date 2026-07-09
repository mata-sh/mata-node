<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Command;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @internal
 *
 * @coversNothing
 */
final class ShellCommandSurfaceTest extends TestCase
{
    public function testProductionShellCommandConstructionStaysCentralized(): void
    {
        $violations = [];
        $root = dirname(__DIR__, 5);
        $src = $root . '/src';

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            if (str_starts_with($relative, 'src/Tests/') || str_starts_with($relative, 'src/Services/Command/')) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            foreach ($this->forbiddenPatterns() as $label => $pattern) {
                if (preg_match($pattern, $contents)) {
                    $violations[] = $relative . ' matches ' . $label;
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * @return array<string,string>
     */
    private function forbiddenPatterns(): array
    {
        return [
            'escapeshellarg' => '/\bescapeshellarg\s*\(/',
            'escapeshellcmd' => '/\bescapeshellcmd\s*\(/',
            'shell_exec' => '/\bshell_exec\s*\(/',
            'proc_open' => '/\bproc_open\s*\(/',
            'popen' => '/\bpopen\s*\(/',
            'passthru' => '/\bpassthru\s*\(/',
            'system' => '/\bsystem\s*\(/',
            'inline head command' => '/["\']head\s+-n\s+/',
            'inline tail command' => '/["\']tail\s+-n\s+/',
            'inline grep command' => '/["\']grep\s+/',
            'inline wc command' => '/["\']wc\s+-l/',
            'inline ps command' => '/["\']ps\s+/',
            'inline pgrep command' => '/["\']pgrep\s+/',
            'inline df command' => '/["\']df\s+-/',
            'inline du command' => '/["\']du\s+-/',
        ];
    }
}
