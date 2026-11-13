<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\JsonConfigLoader;
use Mata\Config\StartupConfigException;
use Mata\Config\StartupConfigInventory;
use Mata\Config\StartupFailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\JsonConfigLoader
 * @covers \Mata\Config\StartupConfigInventory
 *
 * @internal
 */
final class StartupConfigTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/mata-startup-config-' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->configDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->configDir);
    }

    public function testInventoryListsMissingFilesInDeclaredOrder(): void
    {
        file_put_contents($this->configDir . '/config.env', '');
        file_put_contents($this->configDir . '/apps.json', '{}');

        $this->assertSame([
            'databases.json',
            'transmit.json',
        ], StartupConfigInventory::findMissingFiles($this->configDir));
    }

    public function testJsonLoaderRejectsMissingInvalidAndNonArrayFiles(): void
    {
        $loader = new JsonConfigLoader();

        try {
            $loader->loadArray($this->configDir . '/apps.json');
            $this->fail('Expected missing configuration exception.');
        } catch (StartupConfigException $e) {
            $this->assertSame(StartupFailureReason::ConfigurationMissing, $e->getReason());
            $this->assertSame(['apps.json'], $e->getMissingFiles());
        }

        file_put_contents($this->configDir . '/apps.json', '{invalid');
        $this->expectException(StartupConfigException::class);
        $this->expectExceptionMessage('apps.json (Syntax error)');
        $loader->loadArray($this->configDir . '/apps.json');
    }

    public function testJsonLoaderAcceptsObjects(): void
    {
        $loader = new JsonConfigLoader();
        $path = $this->configDir . '/apps.json';

        file_put_contents($path, '{"app": {"enabled": true}}');
        $this->assertSame(['app' => ['enabled' => true]], $loader->loadArray($path));

        file_put_contents($path, '{}');
        $this->assertSame([], $loader->loadArray($path));
    }

    #[DataProvider('nonObjectJsonProvider')]
    public function testJsonLoaderRejectsNonObjectTopLevelValue(string $json): void
    {
        $path = $this->configDir . '/apps.json';
        file_put_contents($path, $json);

        $this->expectException(StartupConfigException::class);
        $this->expectExceptionMessage('must contain an object');
        (new JsonConfigLoader())->loadArray($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonObjectJsonProvider(): iterable
    {
        yield 'string' => ['"not an object"'];

        yield 'list' => ['[{"title": "App"}]'];
    }
}
