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

    public function testAppInfoIncludesDescriptionNestedSessionAndCronjobSummary(): void
    {
        file_put_contents($this->rootPath . '/app/temp/sessions/sess_recent', 'recent-session');
        file_put_contents($this->rootPath . '/app/temp/sessions/sess_old', 'old-session');
        file_put_contents($this->rootPath . '/app/temp/sessions/ignored', 'ignored-session');
        touch($this->rootPath . '/app/temp/sessions/sess_recent', time() - 60);
        touch($this->rootPath . '/app/temp/sessions/sess_old', time() - 7200);

        file_put_contents($this->rootPath . '/app/includes/heartbeat.log', "first\nsecond\n");
        touch($this->rootPath . '/app/includes/heartbeat.log', time() - 10);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig([
                'cronjob-heartbeat' => [
                    'app/includes/heartbeat.log',
                    'app/includes/missing-heartbeat.log',
                ],
                'cronjob-heartbeat-stale-seconds' => 60,
            ]),
        ]);

        $info = $appWatcher->getAppInfo('testapp');

        $this->assertSame('App description', $info['description']);
        $this->assertArrayNotHasKey('session_count_last_hour', $info);
        $this->assertArrayNotHasKey('session_count_last_day', $info);
        $this->assertArrayNotHasKey('session_count_total', $info);
        $this->assertArrayNotHasKey('session_path', $info);

        $this->assertSame([
            'type' => 'file',
            'count_last_hour' => 1,
            'count_last_day' => 2,
            'count_total' => 2,
            'path' => 'app/temp/sessions',
            'size_kb' => round((strlen('recent-session') + strlen('old-session')) / 1024, 2),
        ], $info['session']);

        $this->assertSame([
            'total' => 2,
            'working' => 1,
            'stale' => 0,
            'missing' => 1,
        ], $info['cronjobs']);
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
        $this->assertSame(1, $sessions['count_last_hour']);
        $this->assertSame(1, $sessions['count_last_day']);
        $this->assertSame(1, $sessions['count_total']);
        $this->assertSame('app/temp/sessions', $sessions['path']);
        $this->assertSame(round(strlen('session') / 1024, 2), $sessions['size_kb']);
    }

    public function testAppSpecificCronjobsEndpointReturnsExistingAndMissingHeartbeatFiles(): void
    {
        file_put_contents($this->rootPath . '/app/includes/heartbeat.log', "line 1\nline 2\n");
        touch($this->rootPath . '/app/includes/heartbeat.log', time() - 20);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig([
                'cronjob-heartbeat' => [
                    'app/includes/heartbeat.log',
                    'app/includes/missing-heartbeat.log',
                ],
                'cronjob-heartbeat-stale-seconds' => 60,
            ]),
        ]);

        $cronjobs = $appWatcher->getAppCronjobs('testapp');

        $this->assertCount(2, $cronjobs);
        $this->assertSame(0, $cronjobs[0]['index']);
        $this->assertSame('app/includes/heartbeat.log', $cronjobs[0]['path']);
        $this->assertTrue($cronjobs[0]['exists']);
        $this->assertIsString($cronjobs[0]['mtime']);
        $this->assertIsInt($cronjobs[0]['age_seconds']);
        $this->assertGreaterThanOrEqual(0, $cronjobs[0]['age_seconds']);
        $this->assertSame(strlen("line 1\nline 2\n"), $cronjobs[0]['size']);
        $this->assertFalse($cronjobs[0]['stale']);
        $this->assertSame(['line 1', 'line 2'], $cronjobs[0]['content_tail']);

        $this->assertSame(1, $cronjobs[1]['index']);
        $this->assertSame('app/includes/missing-heartbeat.log', $cronjobs[1]['path']);
        $this->assertFalse($cronjobs[1]['exists']);
        $this->assertNull($cronjobs[1]['mtime']);
        $this->assertNull($cronjobs[1]['age_seconds']);
        $this->assertSame(0, $cronjobs[1]['size']);
        $this->assertFalse($cronjobs[1]['stale']);
        $this->assertSame([], $cronjobs[1]['content_tail']);
    }

    public function testCronjobContentTailLineLimitIsConfigurable(): void
    {
        file_put_contents($this->rootPath . '/app/includes/heartbeat.log', "line 1\nline 2\nline 3\n");

        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig([
                'cronjob-heartbeat' => [
                    'app/includes/heartbeat.log',
                ],
                'cronjob-heartbeat-tail-line-limit' => 1,
            ]),
        ]);

        $cronjobs = $appWatcher->getAppCronjobs('testapp');

        $this->assertSame(['line 3'], $cronjobs[0]['content_tail']);
    }

    public function testCronjobSummaryCountsStaleHeartbeatFiles(): void
    {
        file_put_contents($this->rootPath . '/app/includes/heartbeat.log', 'old');
        touch($this->rootPath . '/app/includes/heartbeat.log', time() - 120);

        $appWatcher = $this->createAppWatcher([
            'testapp' => $this->appConfig([
                'cronjob-heartbeat' => [
                    'app/includes/heartbeat.log',
                ],
                'cronjob-heartbeat-stale-seconds' => 60,
            ]),
        ]);

        $info = $appWatcher->getAppInfo('testapp');

        $this->assertSame([
            'total' => 1,
            'working' => 0,
            'stale' => 1,
            'missing' => 0,
        ], $info['cronjobs']);
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
