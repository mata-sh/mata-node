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

        // No Composer files, only the manifest.
        yield 'joomla' => ['joomla', 'joomla', '5.4.0'];
    }

    public function testAppBuiltFromSymfonyComponentsHasNoFramework(): void
    {
        $this->assertNull($this->detector()->forApp('app', ['path' => 'plain']));
    }

    /**
     * @param null|array{name: string, version: ?string} $expected
     */
    #[DataProvider('declarationProvider')]
    public function testDeclaredFrameworkOverridesDetection(?string $declared, ?array $expected): void
    {
        $this->assertSame(
            $expected,
            $this->detector()->forApp('app', ['path' => 'laravel', 'framework' => $declared])?->toArray()
        );
    }

    /**
     * @return iterable<string, array{?string, null|array{name: string, version: ?string}}>
     */
    public static function declarationProvider(): iterable
    {
        yield 'known, with its own version' => ['symfony', ['name' => 'symfony', 'version' => '7.1.9']];

        yield 'custom' => ['Legacy Shop', ['name' => 'Legacy Shop', 'version' => null]];

        yield 'case-sensitive' => ['Laravel', ['name' => 'Laravel', 'version' => null]];

        yield 'trimmed' => [' symfony ', ['name' => 'symfony', 'version' => '7.1.9']];

        yield 'none' => ['none', null];

        yield 'null detects' => [null, ['name' => 'laravel', 'version' => '11.34.2']];

        yield 'blank detects' => [' ', ['name' => 'laravel', 'version' => '11.34.2']];
    }

    public function testDeclaredFrameworkWithoutInstalledFilesHasNoVersion(): void
    {
        $this->assertSame(
            ['name' => 'wordpress', 'version' => null],
            $this->detector()->forApp('app', ['path' => 'plain', 'framework' => 'wordpress'])?->toArray()
        );
    }

    #[DataProvider('invalidDeclarationProvider')]
    public function testInvalidDeclaredFrameworkIsLoggedAndMeansNone(mixed $declared): void
    {
        $handler = new TestHandler();

        $this->assertNull($this->detector($handler)->forApp('app', ['path' => 'laravel', 'framework' => $declared]));
        $this->assertTrue($handler->hasWarningThatContains('Invalid framework declared for app'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDeclarationProvider(): iterable
    {
        yield 'boolean' => [true];

        yield 'array' => [[]];
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

    public function testAppsPayloadReportsDeclaredOrDetectedFramework(): void
    {
        $watcher = new AppWatcher(new Tools(), new NullLogger(), self::FIXTURES, [
            'shop' => $this->appConfig('wordpress-bedrock'),
            'tool' => $this->appConfig('plain'),
            'custom' => $this->appConfig('laravel', ['framework' => 'Legacy Shop']),
        ], ['errorlog_readout_limit' => 50]);

        $apps = $watcher->getApps();

        $this->assertSame(['name' => 'wordpress', 'version' => '6.8.3'], $apps['shop']['framework']);
        $this->assertArrayNotHasKey('framework', $apps['tool']);
        $this->assertSame(['name' => 'Legacy Shop', 'version' => null], $apps['custom']['framework']);
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

        $this->assertSame('wordpress', $detector->forApp('app', ['path' => 'wordpress-classic'])?->name);
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

    /**
     * @param array<string, string>                 $installed
     * @param array{name: string, version: ?string} $expected
     */
    #[DataProvider('productProvider')]
    public function testProductsWinOverTheFrameworksTheyBuildOn(array $installed, array $expected): void
    {
        $root = $this->tempApp(['vendor/composer/installed.json' => $this->packagesJson($installed + [
            'laravel/framework' => '11.34.2',
            'symfony/framework-bundle' => '7.1.9',
        ])]);

        $this->assertSame($expected, $this->detector(root: $root)->forApp('app', ['path' => 'app'])?->toArray());
    }

    /**
     * @return iterable<string, array{array<string, string>, array{name: string, version: ?string}}>
     */
    public static function productProvider(): iterable
    {
        yield 'statamic on laravel' => [['statamic/cms' => 'v6.38.0'], ['name' => 'statamic', 'version' => '6.38.0']];

        yield 'shopware on symfony' => [['shopware/core' => '6.7.15.1'], ['name' => 'shopware', 'version' => '6.7.15.1']];

        yield 'magento enterprise over community' => [
            ['magento/product-community-edition' => '2.4.7', 'magento/product-enterprise-edition' => '2.4.8-p3'],
            ['name' => 'magento', 'version' => '2.4.8-p3'],
        ];

        // The Rain library version is not the CMS version.
        yield 'october, without version' => [['october/rain' => 'v4.4.11'], ['name' => 'october', 'version' => null]];
    }

    public function testRootProjectIsIdentifiedButNotVersionedByItsManifest(): void
    {
        $root = $this->tempApp(['composer.json' => '{"name": "sylius/sylius", "version": "2.3.2"}']);

        $this->assertSame(
            ['name' => 'sylius', 'version' => null],
            $this->detector(root: $root)->forApp('app', ['path' => 'app'])?->toArray()
        );
    }

    public function testDevOnlyPackagesDoNotIdentify(): void
    {
        $root = $this->tempApp([
            'vendor/composer/installed.json' => '{"packages": [{"name": "laravel/framework", "version": "11.34.2"}], "dev-package-names": ["laravel/framework"]}',
            'composer.lock' => '{"packages": [], "packages-dev": [{"name": "laravel/framework", "version": "11.34.2"}]}',
        ]);

        $this->assertNull($this->detector(root: $root)->forApp('app', ['path' => 'app']));
    }

    public function testConfiguredVendorDirIsHonoured(): void
    {
        $root = $this->tempApp([
            'composer.json' => '{"config": {"vendor-dir": "deps"}}',
            'deps/composer/installed.json' => $this->packagesJson(['laravel/framework' => '11.34.2']),
        ]);

        $this->assertSame(
            ['name' => 'laravel', 'version' => '11.34.2'],
            $this->detector(root: $root)->forApp('app', ['path' => 'app'])?->toArray()
        );
    }

    public function testVersionComesFromInstalledThenSourceThenLock(): void
    {
        $root = $this->tempApp([
            'vendor/composer/installed.json' => $this->packagesJson(['joomla/joomla-cms' => '5.4.1']),
            'administrator/manifests/files/joomla.xml' => '<extension><version>5.4.2</version></extension>',
            'composer.lock' => $this->packagesJson(['joomla/joomla-cms' => '5.4.3']),
        ]);
        $detector = $this->detector(root: $root);

        $this->assertSame('5.4.1', $detector->forApp('app', ['path' => 'app'])?->version);

        unlink($root . '/app/vendor/composer/installed.json');
        $this->assertSame('5.4.2', $detector->forApp('app', ['path' => 'app'])?->version);

        unlink($root . '/app/administrator/manifests/files/joomla.xml');
        $this->assertSame('5.4.3', $detector->forApp('app', ['path' => 'app'])?->version);
    }

    private function detector(?TestHandler $handler = null, string $root = self::FIXTURES): FrameworkDetector
    {
        $logger = $handler === null ? new NullLogger() : new Logger('test', [$handler]);

        return new FrameworkDetector(new Tools(), $logger, $root);
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

    /**
     * A writable app at `<root>/app` holding the given files.
     *
     * @param array<string, string> $files contents by path relative to the app
     */
    private function tempApp(array $files): string
    {
        $this->tempRoot = sys_get_temp_dir() . '/mata-node-frameworks-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $contents) {
            $file = $this->tempRoot . '/app/' . $path;
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $contents);
        }

        return $this->tempRoot;
    }

    /**
     * `installed.json` / `composer.lock` contents.
     *
     * @param array<string, string> $versions version by package name
     */
    private function packagesJson(array $versions): string
    {
        $packages = [];
        foreach ($versions as $name => $version) {
            $packages[] = ['name' => $name, 'version' => $version];
        }

        return json_encode(['packages' => $packages], JSON_THROW_ON_ERROR);
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
