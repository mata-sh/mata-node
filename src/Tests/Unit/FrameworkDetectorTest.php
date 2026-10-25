<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use FilesystemIterator;
use Mata\Services\FileCache;
use Mata\Utils\Tools;
use Mata\Watchers\App\FrameworkDetector;
use Mata\Watchers\AppWatcher;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @covers \Mata\Watchers\App\FrameworkDetector
 *
 * @internal
 */
final class FrameworkDetectorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/frameworks';

    private ?string $tempRoot = null;

    protected function tearDown(): void
    {
        if ($this->tempRoot !== null) {
            $this->removeDirectory($this->tempRoot);
        }
    }

    #[DataProvider('installationProvider')]
    public function testDetectsFrameworkAndInstalledVersion(string $path, string $name, string $version): void
    {
        $this->assertSame(
            ['name' => $name, 'version' => $version],
            $this->detector()->forApp('app', ['path' => $path])?->toArray()
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function installationProvider(): iterable
    {
        // Ships symfony/http-kernel and symfony/console.
        yield 'laravel, not symfony' => ['laravel', 'laravel', '11.34.2'];

        // composer.lock lists 7.1.10, but 7.1.9 is what vendor/ holds.
        yield 'symfony, installed over locked' => ['symfony', 'symfony', '7.1.9'];

        // Ships Symfony components; core under web/.
        yield 'drupal, not symfony' => ['drupal', 'drupal', '10.3.6'];

        yield 'typo3 classic' => ['typo3-classic', 'typo3', '12.4.20'];

        yield 'wordpress classic' => ['wordpress-classic', 'wordpress', '6.8.3'];

        yield 'wordpress via composer (bedrock)' => ['wordpress-bedrock', 'wordpress', '6.8.3'];
    }

    public function testAppBuiltFromSymfonyComponentsHasNoFramework(): void
    {
        $this->assertNull($this->detector()->forApp('app', ['path' => 'plain']));
    }

    public function testDeclaredFrameworkOverridesDetection(): void
    {
        $this->assertSame(
            ['name' => 'symfony', 'version' => '7.1.9'],
            $this->detector()->forApp('app', ['path' => 'laravel', 'framework' => 'symfony'])?->toArray()
        );
    }

    public function testDeclaredNoneSkipsDetection(): void
    {
        $this->assertNull($this->detector()->forApp('app', ['path' => 'laravel', 'framework' => 'none']));
    }

    public function testDeclaredFrameworkWithoutInstalledFilesHasNoVersion(): void
    {
        $this->assertSame(
            ['name' => 'wordpress', 'version' => null],
            $this->detector()->forApp('app', ['path' => 'plain', 'framework' => 'wordpress'])?->toArray()
        );
    }

    #[DataProvider('unknownDeclarationProvider')]
    public function testUnknownDeclaredFrameworkIsLoggedAndMeansNone(mixed $declared): void
    {
        $handler = new TestHandler();

        $this->assertNull($this->detector($handler)->forApp('app', ['path' => 'laravel', 'framework' => $declared]));
        $this->assertTrue($handler->hasWarningThatContains('Unknown framework declared for app'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unknownDeclarationProvider(): iterable
    {
        yield 'unsupported name' => ['joomla'];

        yield 'wrong case' => ['WordPress'];

        yield 'not a string' => [true];
    }

    public function testInvalidComposerLockIsLoggedAndSkipped(): void
    {
        $handler = new TestHandler();

        $framework = $this->detector($handler)->forApp('app', [
            'path' => 'wordpress-classic',
            'composer' => ['lock' => 'invalid-composer/composer.lock'],
        ]);

        $this->assertSame(['name' => 'wordpress', 'version' => '6.8.3'], $framework?->toArray());
        $this->assertTrue($handler->hasWarningThatContains('Framework detection skipped invalid JSON'));
    }

    public function testAppsPayloadReportsFrameworkOnlyWhenKnown(): void
    {
        $watcher = new AppWatcher(new Tools(), new NullLogger(), self::FIXTURES, [
            'shop' => $this->appConfig('wordpress-bedrock'),
            'tool' => $this->appConfig('plain'),
            'typo' => $this->appConfig('laravel', ['framework' => 'lavarel']),
        ], ['errorlog_readout_limit' => 50]);

        $apps = $watcher->getApps();

        $this->assertSame(['name' => 'wordpress', 'version' => '6.8.3'], $apps['shop']['framework']);
        $this->assertArrayNotHasKey('framework', $apps['tool']);
        $this->assertArrayNotHasKey('framework', $apps['typo']);
    }

    public function testCachedResultFollowsADeploy(): void
    {
        $root = $this->copyFixtures('laravel');
        $detector = $this->cachingDetector($root);
        $this->assertSame('11.34.2', $detector->forApp('app', ['path' => 'laravel'])?->version);

        // A deploy rewrites installed.json and the framework source, typically at the same size.
        $installed = $root . '/laravel/vendor/composer/installed.json';
        file_put_contents($installed, str_replace('v11.34.2', 'v11.35.0', (string) file_get_contents($installed)));
        touch($installed, time() + 5);

        $this->assertSame('11.35.0', $detector->forApp('app', ['path' => 'laravel'])?->version);
    }

    public function testCachedAbsenceNoticesALaterInstall(): void
    {
        $root = $this->copyFixtures('wordpress-classic');
        rename($root . '/wordpress-classic/wp-includes/version.php', $root . '/version.php');
        $detector = $this->cachingDetector($root);
        $this->assertNull($detector->forApp('app', ['path' => 'wordpress-classic']));

        rename($root . '/version.php', $root . '/wordpress-classic/wp-includes/version.php');

        $this->assertSame('wordpress', $detector->forApp('app', ['path' => 'wordpress-classic'])?->framework->value);
    }

    public function testChangedDeclarationBypassesTheCache(): void
    {
        $detector = $this->cachingDetector($this->copyFixtures('laravel'));
        $detector->forApp('app', ['path' => 'laravel']);

        $this->assertSame(
            ['name' => 'symfony', 'version' => '7.1.9'],
            $detector->forApp('app', ['path' => 'laravel', 'framework' => 'symfony'])?->toArray()
        );
    }

    public function testUnchangedFilesAreNotParsedAgain(): void
    {
        $root = $this->copyFixtures('wordpress-classic', 'invalid-composer');
        $handler = new TestHandler();
        $detector = $this->cachingDetector($root, $handler);
        $config = ['path' => 'wordpress-classic', 'composer' => ['lock' => 'invalid-composer/composer.lock']];

        $detector->forApp('app', $config);
        $second = $detector->forApp('app', $config);

        $this->assertSame(['name' => 'wordpress', 'version' => '6.8.3'], $second?->toArray());
        // The invalid lock file is only parsed, and reported, on the first request.
        $this->assertCount(1, array_filter(
            $handler->getRecords(),
            static fn ($record): bool => $record->message === 'Framework detection skipped invalid JSON'
        ));
    }

    private function detector(?TestHandler $handler = null): FrameworkDetector
    {
        $logger = $handler === null ? new NullLogger() : new Logger('test', [$handler]);

        return new FrameworkDetector(new Tools(), $logger, self::FIXTURES);
    }

    private function cachingDetector(string $root, ?TestHandler $handler = null): FrameworkDetector
    {
        $logger = $handler === null ? new NullLogger() : new Logger('test', [$handler]);

        return new FrameworkDetector(new Tools(), $logger, $root, new FileCache($root . '/.cache'));
    }

    /**
     * Writable copies of fixture installs, since the cache tests change files.
     */
    private function copyFixtures(string ...$names): string
    {
        $this->tempRoot = sys_get_temp_dir() . '/mata-node-frameworks-' . bin2hex(random_bytes(6));
        foreach ($names as $name) {
            $source = self::FIXTURES . '/' . $name;
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $target = $this->tempRoot . '/' . $name . substr($file->getPathname(), strlen($source));
                @mkdir(dirname($target), 0777, true);
                copy($file->getPathname(), $target);
            }
        }

        return $this->tempRoot;
    }

    private function removeDirectory(string $path): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function appConfig(string $path, array $overrides = []): array
    {
        return array_replace([
            'title' => 'App',
            'url' => 'https://example.com/',
            'path' => $path,
            'env' => 'prod',
            'monitoring' => ['loganalysis' => false, 'logsize' => false, 'sessions' => false],
        ], $overrides);
    }
}
