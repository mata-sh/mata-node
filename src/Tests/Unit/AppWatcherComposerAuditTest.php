<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Services\Command\CommandResult;
use Mata\Tests\Support\FakeProcessCommandRunner;
use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Watchers\AppWatcher
 *
 * @internal
 */
final class AppWatcherComposerAuditTest extends TestCase
{
    public function testComposerAuditUsesGlobalCommandDefaultsForAutoConfiguredApp(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-composer-audit-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);
        file_put_contents($rootPath . '/app/composer.json', '{}');
        file_put_contents($rootPath . '/app/composer.lock', '{}');
        $processRunner = new FakeProcessCommandRunner(new CommandResult('{}', '', 0, false, false, 10));

        try {
            $watcher = new AppWatcher(
                new Tools(),
                new NullLogger(),
                $rootPath,
                ['app' => ['path' => 'app', 'composer' => true]],
                [],
                processRunner: $processRunner,
                composerBin: 'tools/composer.phar',
                composerPhpBin: '/usr/bin/php8.3-cli',
            );

            $result = $watcher->getAppComposerAudit('app');

            $this->assertSame('ok', $result['status']);
            $this->assertStringStartsWith(
                escapeshellarg('/usr/bin/php8.3-cli') . ' ' . escapeshellarg($rootPath . '/tools/composer.phar'),
                $processRunner->calls[0]['command'],
            );
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testComposerConfigurationFieldsEnableAuditUnlessExplicitlyDisabled(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-composer-audit-' . bin2hex(random_bytes(6));
        foreach (['configured', 'disabled', 'missing'] as $app) {
            mkdir($rootPath . '/' . $app, 0777, true);
            file_put_contents($rootPath . '/' . $app . '/composer.json', '{}');
            file_put_contents($rootPath . '/' . $app . '/composer.lock', '{}');
        }
        $processRunner = new FakeProcessCommandRunner(new CommandResult('{}', '', 0, false, false, 10));

        try {
            $watcher = new AppWatcher(
                new Tools(),
                new NullLogger(),
                $rootPath,
                [
                    'configured' => ['path' => 'configured', 'composer' => ['bin' => 'composer.phar']],
                    'disabled' => ['path' => 'disabled', 'composer' => ['enabled' => false, 'bin' => 'composer.phar']],
                    'missing' => ['path' => 'missing'],
                ],
                [],
                processRunner: $processRunner,
            );

            $result = $watcher->getAppsComposerAudit();

            $this->assertSame(['configured'], array_keys($result));
            $this->assertCount(1, $processRunner->calls);
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testComposerAuditOverridesGlobalDefaultsIndependently(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-composer-audit-' . bin2hex(random_bytes(6));
        foreach (['bin-override', 'php-override'] as $app) {
            mkdir($rootPath . '/' . $app, 0777, true);
            file_put_contents($rootPath . '/' . $app . '/composer.json', '{}');
            file_put_contents($rootPath . '/' . $app . '/composer.lock', '{}');
        }
        $processRunner = new FakeProcessCommandRunner(new CommandResult('{}', '', 0, false, false, 10));

        try {
            $watcher = new AppWatcher(
                new Tools(),
                new NullLogger(),
                $rootPath,
                [
                    'bin-override' => [
                        'composer' => [
                            'json' => 'bin-override/composer.json',
                            'lock' => 'bin-override/composer.lock',
                            'bin' => 'overrides/composer.phar',
                            'php_bin' => '',
                        ],
                    ],
                    'php-override' => [
                        'composer' => [
                            'json' => 'php-override/composer.json',
                            'lock' => 'php-override/composer.lock',
                            'bin' => '',
                            'php_bin' => 'overrides/php',
                        ],
                    ],
                ],
                [],
                processRunner: $processRunner,
                composerBin: 'defaults/composer.phar',
                composerPhpBin: 'defaults/php',
            );

            $watcher->getAppsComposerAudit();

            $this->assertStringStartsWith(
                escapeshellarg($rootPath . '/defaults/php') . ' ' . escapeshellarg($rootPath . '/overrides/composer.phar'),
                $processRunner->calls[0]['command'],
            );
            $this->assertStringStartsWith(
                escapeshellarg($rootPath . '/overrides/php') . ' ' . escapeshellarg($rootPath . '/defaults/composer.phar'),
                $processRunner->calls[1]['command'],
            );
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testComposerAuditReturnsTimeoutError(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-composer-audit-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);
        file_put_contents($rootPath . '/app/composer.json', '{}');
        file_put_contents($rootPath . '/app/composer.lock', '{}');

        $processRunner = new FakeProcessCommandRunner(new CommandResult('', 'late', 124, true, false, 60_000));

        try {
            $watcher = new AppWatcher(
                new Tools(),
                new NullLogger(),
                $rootPath,
                ['app' => ['path' => 'app', 'composer' => true]],
                [],
                null,
                null,
                null,
                $processRunner,
                $rootPath . '/temp/cache/composer',
            );

            $result = $watcher->getAppsComposerAudit();

            $this->assertSame('error', $result['app']['status']);
            $this->assertSame(124, $result['app']['exit_code']);
            $this->assertSame('composer audit timed out', $result['app']['message']);
            $this->assertSame(60, $processRunner->calls[0]['timeoutSeconds']);
            $this->assertStringStartsWith(
                'COMPOSER_HOME=' . escapeshellarg($rootPath . '/temp/cache/composer') . ' composer --no-plugins --no-scripts audit',
                $processRunner->calls[0]['command']
            );
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testComposerAuditLogsInvalidJsonOutput(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-composer-audit-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);
        file_put_contents($rootPath . '/app/composer.json', '{}');
        file_put_contents($rootPath . '/app/composer.lock', '{}');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Composer audit returned invalid JSON', $this->callback(static function (array $context) use ($rootPath): bool {
                return $context['composer_json'] === $rootPath . '/app/composer.json'
                    && $context['composer_lock'] === $rootPath . '/app/composer.lock'
                    && $context['exit_code'] === 1
                    && $context['json_error'] === 'Syntax error'
                    && $context['stdout_preview'] === 'Composer failed'
                    && $context['stderr_preview'] === 'Missing extension';
            }));

        try {
            $watcher = new AppWatcher(
                new Tools(),
                $logger,
                $rootPath,
                ['app' => ['path' => 'app', 'composer' => true]],
                [],
                null,
                null,
                null,
                new FakeProcessCommandRunner(new CommandResult('Composer failed', 'Missing extension', 1, false, false, 10)),
            );

            $result = $watcher->getAppComposerAudit('app');

            $this->assertSame('error', $result['status']);
            $this->assertSame('composer audit did not return valid JSON', $result['message']);
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testComposerAuditClassifiesHttpOutputAsACommandError(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-composer-audit-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);
        file_put_contents($rootPath . '/app/composer.json', '{}');
        file_put_contents($rootPath . '/app/composer.lock', '{}');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Composer audit returned HTTP response', $this->callback(static function (array $context): bool {
                return $context['exit_code'] === 0
                    && $context['command_http_status'] === 401;
            }));

        try {
            $watcher = new AppWatcher(
                new Tools(),
                $logger,
                $rootPath,
                ['app' => ['path' => 'app', 'composer' => true]],
                [],
                null,
                null,
                null,
                new FakeProcessCommandRunner(new CommandResult(
                    "Status: 401 Unauthorized\nContent-Type: application/json\n\n{\"error\":\"Unauthorized\"}",
                    '',
                    0,
                    false,
                    false,
                    10,
                )),
            );

            $result = $watcher->getAppComposerAudit('app');

            $this->assertSame('error', $result['status']);
            $this->assertSame(0, $result['exit_code']);
            $this->assertSame('composer command returned HTTP 401 instead of audit JSON', $result['message']);
            $this->assertArrayNotHasKey('stdout', $result);
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    public function testComposerAuditReturnsOutputLimitError(): void
    {
        $rootPath = sys_get_temp_dir() . '/mata-node-composer-audit-' . bin2hex(random_bytes(6));
        mkdir($rootPath . '/app', 0777, true);
        file_put_contents($rootPath . '/app/composer.json', '{}');
        file_put_contents($rootPath . '/app/composer.lock', '{}');

        $processRunner = new FakeProcessCommandRunner(new CommandResult('{"partial":', '', 125, false, true, 10));

        try {
            $watcher = new AppWatcher(
                new Tools(),
                new NullLogger(),
                $rootPath,
                ['app' => ['path' => 'app', 'composer' => true]],
                [],
                null,
                null,
                null,
                $processRunner,
            );

            $result = $watcher->getAppsComposerAudit();

            $this->assertSame('error', $result['app']['status']);
            $this->assertSame(125, $result['app']['exit_code']);
            $this->assertSame('composer audit output exceeded maximum size', $result['app']['message']);
        } finally {
            $this->removeDirectory($rootPath);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
