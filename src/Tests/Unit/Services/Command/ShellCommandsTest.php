<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Command;

use InvalidArgumentException;
use Mata\Services\Command\ShellCommands;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Command\ShellCommands
 * @covers \Mata\Services\Command\ShellInput
 *
 * @internal
 */
final class ShellCommandsTest extends TestCase
{
    public function testTailEscapesPathsAndClampsLines(): void
    {
        $path = "/tmp/mata a'b.log";

        $this->assertSame('tail -n 1 -- ' . escapeshellarg($path), ShellCommands::tail($path, -10));
    }

    public function testHeadEscapesPathsBeginningWithDash(): void
    {
        $path = '-weird.log';

        $this->assertSame('head -n 10 -- ' . escapeshellarg($path), ShellCommands::head($path, 10));
    }

    public function testLineCountUsesShellRedirectionForBareCount(): void
    {
        $path = '/tmp/a b.log';

        $this->assertSame('wc -l < ' . escapeshellarg($path), ShellCommands::lineCount($path));
    }

    public function testGrepCountEscapesPatternAndPath(): void
    {
        $pattern = 'PHP Fatal error\|PHP Parse error';
        $path = '/tmp/error log.log';

        $this->assertSame(
            'grep -c ' . escapeshellarg($pattern) . ' -- ' . escapeshellarg($path) . ' 2>/dev/null',
            ShellCommands::grepCount($pattern, $path)
        );
    }

    public function testComposerAuditUsesDefaultComposerCommand(): void
    {
        $workingDir = '/tmp/project dir';

        $this->assertSame(
            'composer --no-plugins --no-scripts audit --format=json --no-ansi --no-interaction --working-dir=' . escapeshellarg($workingDir),
            ShellCommands::composerAudit(null, $workingDir)
        );
    }

    public function testComposerAuditSetsComposerHome(): void
    {
        $composerHome = '/tmp/mata cache/composer';

        $this->assertSame(
            'COMPOSER_HOME=' . escapeshellarg($composerHome) . ' composer --no-plugins --no-scripts audit --format=json --no-ansi --no-interaction --working-dir=' . escapeshellarg('/tmp/project'),
            ShellCommands::composerAudit(null, '/tmp/project', $composerHome)
        );
    }

    public function testComposerAuditUsesPhpForPhar(): void
    {
        $binPath = '/tmp/composer tool.phar';
        $workingDir = '/tmp/project';

        $this->assertSame(
            'php ' . escapeshellarg($binPath) . ' --no-plugins --no-scripts audit --format=json --no-ansi --no-interaction --working-dir=' . escapeshellarg($workingDir),
            ShellCommands::composerAudit($binPath, $workingDir)
        );
    }

    public function testComposerAuditUsesConfiguredPhpBinaryForPhar(): void
    {
        $phpBinary = '/usr/bin/php8.3-cli';
        $binPath = '/tmp/composer.phar';

        $this->assertSame(
            escapeshellarg($phpBinary) . ' ' . escapeshellarg($binPath) . ' --no-plugins --no-scripts audit --format=json --no-ansi --no-interaction --working-dir=' . escapeshellarg('/tmp/project'),
            ShellCommands::composerAudit($binPath, '/tmp/project', phpBinary: $phpBinary)
        );
    }

    public function testProcessCountRejectsShellMetacharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ShellCommands::processCount('php; rm -rf /', false);
    }

    public function testProcessCountAllowsAlternationPattern(): void
    {
        $this->assertSame(
            'pgrep -cx ' . escapeshellarg('mysqld|mariadbd') . ' 2>/dev/null',
            ShellCommands::processCount('mysqld|mariadbd', true)
        );
    }

    public function testEmptyPathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ShellCommands::tail('', 10);
    }
}
