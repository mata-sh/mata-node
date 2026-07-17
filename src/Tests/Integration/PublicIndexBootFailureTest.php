<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class PublicIndexBootFailureTest extends TestCase
{
    private string $appPath;
    private string $workingPath;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for public index tests.');
        }

        $root = sys_get_temp_dir() . '/mata-public-index-' . bin2hex(random_bytes(6));
        $this->appPath = $root . '/app';
        $this->workingPath = $root . '/working';

        mkdir($this->appPath . '/public', 0775, true);
        mkdir($this->appPath . '/vendor', 0775, true);
        mkdir($this->appPath . '/logs', 0775, true);
        mkdir($this->workingPath, 0775, true);

        $projectRoot = dirname(__DIR__, 3);
        copy($projectRoot . '/public/index.php', $this->appPath . '/public/index.php');
        file_put_contents(
            $this->appPath . '/vendor/autoload.php',
            '<?php require ' . var_export($projectRoot . '/vendor/autoload.php', true) . ';'
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(dirname($this->appPath));
    }

    public function testMissingConfigResponsesDoNotDependOnWorkingDirectory(): void
    {
        [$status, $output, $error] = $this->request('/health?probe=1');

        $this->assertSame(0, $status, $error);
        $this->assertSame('', $error);
        $this->assertSame([
            'status' => 'not_ready',
            'reason' => 'configuration_missing',
            'missing_config_files' => [
                'config.env',
                'mail.config.env',
                'apps.json',
                'databases.json',
                'transmit.json',
            ],
        ], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testMissingConfigReturnsSameJsonForNonHealthRequest(): void
    {
        [$status, $output, $error] = $this->request('/apps');

        $this->assertSame(0, $status, $error);
        $this->assertSame('', $error);
        $this->assertStringNotContainsString($this->appPath, $output);
        $this->assertStringNotContainsString('Configuration loading failed', $output);

        $body = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('not_ready', $body['status']);
        $this->assertSame('configuration_missing', $body['reason']);
    }

    /**
     * @return array{int, string, string}
     */
    private function request(string $requestUri): array
    {
        $script = '$_SERVER[\'REQUEST_URI\'] = ' . var_export($requestUri, true) . '; require ' . var_export($this->appPath . '/public/index.php', true) . ';';
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->workingPath,
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertIsString($output);
        $this->assertIsString($error);

        return [$status, $output, $error];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
