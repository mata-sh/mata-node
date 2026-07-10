<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Watchers\AppWatcher
 *
 * @internal
 */
class AppMetadataTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/mata-node-app-metadata-' . bin2hex(random_bytes(6));
        mkdir($this->rootPath . '/app/config', 0777, true);
        mkdir($this->rootPath . '/app/temp/sessions', 0777, true);
        mkdir($this->rootPath . '/app/includes', 0777, true);

        file_put_contents($this->rootPath . '/app/config/config.env', implode("\n", [
            'APP_ENV=testing',
            'MAINTENANCE_ENABLED=off',
            'DISPLAY_ERRORS=on',
            'LOG_HELPER_IMPORTANT=warning',
            'LOG_HELPER=on',
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootPath);
    }

    public function testAppInfoIncludesDescriptionAndNestedSessionMetadata(): void
    {
        file_put_contents($this->rootPath . '/app/temp/sessions/sess_recent', 'recent-session');
        file_put_contents($this->rootPath . '/app/temp/sessions/sess_old', 'old-session');
        file_put_contents($this->rootPath . '/app/temp/sessions/ignored', 'ignored-session');
        touch($this->rootPath . '/app/temp/sessions/sess_recent', time() - 60);
        touch($this->rootPath . '/app/temp/sessions/sess_old', time() - 7200);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig(),
        ]);

        $info = $appWatcher->getAppInfo('testapp');

        $this->assertSame('App description', $info['description']);
        $this->assertArrayNotHasKey('session_count_last_hour', $info);
        $this->assertArrayNotHasKey('session_count_last_day', $info);
        $this->assertArrayNotHasKey('session_count_total', $info);
        $this->assertArrayNotHasKey('session_path', $info);

        $this->assertSame([
            'type' => 'file',
            'status' => 'ok',
            'count_last_hour' => 1,
            'count_last_day' => 2,
            'count_total' => 2,
            'path' => 'app/temp/sessions',
            'size_kb' => round((strlen('recent-session') + strlen('old-session')) / 1024, 2),
        ], $info['session']);
    }

    public function testAppSpecificSessionsEndpointPayload(): void
    {
        file_put_contents($this->rootPath . '/app/temp/sessions/sess_recent', 'session');
        touch($this->rootPath . '/app/temp/sessions/sess_recent', time() - 60);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig(),
        ]);

        $sessions = $appWatcher->getAppSessions('testapp');

        $this->assertSame('file', $sessions['type']);
        $this->assertSame('ok', $sessions['status']);
        $this->assertSame(1, $sessions['count_last_hour']);
        $this->assertSame(1, $sessions['count_last_day']);
        $this->assertSame(1, $sessions['count_total']);
        $this->assertSame('app/temp/sessions', $sessions['path']);
        $this->assertSame(round(strlen('session') / 1024, 2), $sessions['size_kb']);
    }

    public function testMissingSessionConfigReportsUnconfiguredSessionBackend(): void
    {
        file_put_contents($this->rootPath . '/app/temp/sessions/sess_recent', 'session');

        $config = $this->appConfig();
        unset($config['sessions']);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $config,
        ]);

        $expected = [
            'type' => 'none',
            'status' => 'unconfigured',
            'count_last_hour' => null,
            'count_last_day' => null,
            'count_total' => null,
            'path' => null,
            'size_kb' => null,
        ];

        $this->assertSame($expected, $appWatcher->getAppSessions('testapp'));
        $this->assertSame($expected, $appWatcher->getAppInfo('testapp')['session']);
    }

    private function createAppWatcher(array $watcherConfig): AppWatcher
    {
        return new AppWatcher(new Tools(), new NullLogger(), $this->rootPath, $watcherConfig, [
            'transmit_config' => [
                'APP_ENV',
                'MAINTENANCE_ENABLED',
                'DISPLAY_ERRORS',
                'LOG_HELPER_IMPORTANT',
                'LOG_HELPER',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function appConfig(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Test App',
            'url' => 'https://example.com/test',
            'path' => 'app',
            'description' => 'App description',
            'sessions' => [
                'type' => 'file',
                'path' => 'app/temp/sessions',
                'filename_regex' => '/^sess_/',
            ],
            'logs' => [],
            'configs' => [
                'app/config/config.env',
            ],
        ], $overrides);
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
