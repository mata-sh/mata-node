<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

use JsonException;
use Mata\Services\FileCache;
use Mata\Utils\Tools;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Identifies an app's framework or CMS and its running version from the deployed files.
 *
 * Only reads files: Composer's installed and locked package lists and the version constant
 * each framework keeps in its own source. It never executes application code or tools such
 * as wp-cli, artisan or bin/console. The version comes from what is installed, never from a
 * `composer.json` constraint; when no source yields one, the framework is reported without.
 *
 * Detection never fails `/apps`: unreadable or invalid files are logged and skipped.
 *
 * Results are cached per app with a fingerprint of every file that could change them, so
 * `/apps` only stats those files and parses Composer data again after a deploy.
 *
 * @phpstan-type Layout array{manifest_file: string, installed_file: string, lock_file: string, vendor_dir: string, wordpress_dir: ?string, roots: list<string>}
 * @phpstan-type Packages array{installed: array<string, string>, locked: array<string, string>}
 */
final class FrameworkDetector
{
    /**
     * `apps.json` value that switches detection off for an app.
     */
    public const NONE = 'none';

    private const MAX_JSON_BYTES = 32 * 1024 * 1024;
    private const MAX_SOURCE_BYTES = 256 * 1024;
    private const MAX_VERSION_LENGTH = 64;

    private const VERSION_PATTERN = '/^v?(\d+\.\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?)$/';
    private const VERSION_CONSTANT_PATTERN = '/\bconst\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/';
    private const WORDPRESS_VERSION_PATTERN = '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/';
    private const CACHE_KEY_PREFIX = 'frameworks/';

    /**
     * Backstop for changes the fingerprint cannot see, such as an in-place rewrite of a file
     * within the same second and at the same size.
     */
    private const CACHE_TTL_SECONDS = 86400;

    /**
     * Bump when detection rules change, so a node upgrade does not serve results cached by the old rules.
     */
    private const CACHE_SCHEMA = 1;

    public function __construct(
        private readonly Tools $tools,
        private readonly LoggerInterface $logger,
        private readonly string $rootPath,
        private readonly ?FileCache $cache = null,
    ) {}

    /**
     * The declared framework, otherwise the detected one; `null` for none.
     *
     * @param array<string, mixed> $config
     */
    public function forApp(string $app, array $config): ?FrameworkInstallation
    {
        $declared = $config['framework'] ?? null;
        if ($declared === self::NONE) {
            return null;
        }

        $framework = is_string($declared) ? Framework::tryFrom($declared) : null;
        if ($declared !== null && $framework === null) {
            $this->logger->warning('Unknown framework declared for app', [
                'app' => $app,
                'field' => 'framework',
                'supported' => array_merge(array_column(Framework::cases(), 'value'), [self::NONE]),
            ]);

            return null;
        }

        // A declared framework is still reported, without version, when its files cannot be read.
        $fallback = $framework === null ? null : new FrameworkInstallation($framework, null);

        try {
            $layout = $this->layout($app, $config);
            if ($layout === null) {
                return $fallback;
            }

            $cacheKey = self::CACHE_KEY_PREFIX . $app;
            $fingerprint = $this->fingerprint($framework, $layout);
            $cached = $this->cachedInstallation($cacheKey, $fingerprint);
            if ($cached !== false) {
                return $cached;
            }

            $packages = $this->packages($app, $layout);
            $framework ??= $this->detect($layout, $packages);
            $installation = $framework === null
                ? null
                : new FrameworkInstallation($framework, $this->version($framework, $layout, $packages));
            $this->cache?->set($cacheKey, ['fingerprint' => $fingerprint, 'framework' => $installation?->toArray()]);

            return $installation;
        } catch (Throwable $e) {
            $this->logger->warning('Framework detection failed', [
                'app' => $app,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * Where detection looks. Cheap enough for every request: it only reads `composer.json`.
     *
     * The Composer root is the configured `composer.lock` (or `composer.json`) directory, so a
     * Bedrock site whose `path` is the web root still finds its packages one level up.
     *
     * @param array<string, mixed> $config
     *
     * @return null|Layout
     */
    private function layout(string $app, array $config): ?array
    {
        $base = $this->resolvePath($config['path'] ?? null, $this->rootPath);
        if ($base === null) {
            return null;
        }

        $composer = is_array($config['composer'] ?? null) ? $config['composer'] : [];
        $configuredLock = $this->resolvePath($composer['lock'] ?? null, $this->rootPath);
        $configuredManifest = $this->resolvePath($composer['json'] ?? null, $this->rootPath);
        $composerRoot = dirname($configuredLock ?? $configuredManifest ?? $base . '/composer.json');

        $manifestFile = $configuredManifest ?? $composerRoot . '/composer.json';
        $manifest = $this->readJson($app, $manifestFile) ?? [];
        $vendorDir = $this->resolvePath($manifest['config']['vendor-dir'] ?? null, $composerRoot)
            ?? $composerRoot . '/vendor';

        return [
            'manifest_file' => $manifestFile,
            'installed_file' => $vendorDir . '/composer/installed.json',
            'lock_file' => $configuredLock ?? $composerRoot . '/composer.lock',
            'vendor_dir' => $vendorDir,
            'wordpress_dir' => $this->resolvePath($manifest['extra']['wordpress-install-dir'] ?? null, $composerRoot),
            'roots' => array_values(array_unique([$base, $composerRoot])),
        ];
    }

    /**
     * Hash of the declaration and of every file whose content or presence affects the result.
     *
     * Missing files are part of it too, so a framework installed later is noticed. The inode
     * catches atomic deploys that swap in a file with an older mtime.
     *
     * @param Layout $layout
     */
    private function fingerprint(?Framework $declared, array $layout): string
    {
        $files = [$layout['manifest_file'], $layout['installed_file'], $layout['lock_file']];
        foreach ($declared === null ? Framework::cases() : [$declared] as $framework) {
            array_push($files, ...$this->versionFiles($framework, $layout));
        }

        $parts = [self::CACHE_SCHEMA, $declared->value ?? ''];
        foreach (array_unique($files) as $file) {
            $parts[] = $file . ':' . $this->fileStamp($file);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * `mtime/size/inode`, or `-` for a missing file.
     */
    private function fileStamp(string $file): string
    {
        $stat = is_file($file) ? stat($file) : false;

        return $stat === false ? '-' : sprintf('%d/%d/%d', $stat['mtime'], $stat['size'], $stat['ino']);
    }

    /**
     * The cached installation for an unchanged fingerprint; `false` when it has to be detected again.
     */
    private function cachedInstallation(string $key, string $fingerprint): false|FrameworkInstallation|null
    {
        $entry = $this->cache?->get($key, self::CACHE_TTL_SECONDS);
        if (!is_array($entry) || ($entry['fingerprint'] ?? null) !== $fingerprint) {
            return false;
        }

        $stored = $entry['framework'] ?? null;
        if ($stored === null) {
            return null;
        }

        if (!is_array($stored) || !is_string($stored['name'] ?? null)) {
            return false;
        }

        $framework = Framework::tryFrom($stored['name']);
        $version = $stored['version'] ?? null;
        if ($framework === null || ($version !== null && !is_string($version))) {
            return false;
        }

        return new FrameworkInstallation($framework, $version);
    }

    /**
     * @param Layout $layout
     *
     * @return Packages
     */
    private function packages(string $app, array $layout): array
    {
        return [
            'installed' => $this->installedPackages($app, $layout['installed_file']),
            'locked' => $this->lockedPackages($app, $layout['lock_file']),
        ];
    }

    /**
     * Packages from `vendor/composer/installed.json`, without dev-only ones.
     *
     * Composer 2 wraps the list in `packages`; Composer 1 wrote the bare list.
     *
     * @return array<string, string>
     */
    private function installedPackages(string $app, string $file): array
    {
        $data = $this->readJson($app, $file);
        if ($data === null) {
            return [];
        }

        $packages = $this->packageMap(array_is_list($data) ? $data : ($data['packages'] ?? []));
        $devNames = $data['dev-package-names'] ?? [];
        foreach (is_array($devNames) ? $devNames : [] as $name) {
            if (is_string($name)) {
                unset($packages[strtolower($name)]);
            }
        }

        return $packages;
    }

    /**
     * Runtime packages from `composer.lock`; `packages-dev` never holds the app's framework.
     *
     * @return array<string, string>
     */
    private function lockedPackages(string $app, string $file): array
    {
        return $this->packageMap($this->readJson($app, $file)['packages'] ?? []);
    }

    /**
     * @return array<string, string> lowercase package name to raw version
     */
    private function packageMap(mixed $packages): array
    {
        $map = [];
        foreach (is_array($packages) ? $packages : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $map[strtolower($package['name'])] = $package['version'];
            }
        }

        return $map;
    }

    /**
     * @param Layout   $layout
     * @param Packages $packages
     */
    private function detect(array $layout, array $packages): ?Framework
    {
        foreach (Framework::DETECTION_ORDER as $framework) {
            foreach ($framework->identifyingPackages() as $package) {
                if (isset($packages['installed'][$package]) || isset($packages['locked'][$package])) {
                    return $framework;
                }
            }

            // Symfony's version file ships in http-kernel, which many non-Symfony apps use.
            if ($framework === Framework::Symfony) {
                continue;
            }

            foreach ($this->versionFiles($framework, $layout) as $file) {
                if (is_file($file)) {
                    return $framework;
                }
            }
        }

        return null;
    }

    /**
     * Installed packages first, then the framework's own version constant, then the lock file,
     * which may list a version that was never deployed.
     *
     * @param Layout   $layout
     * @param Packages $packages
     */
    private function version(Framework $framework, array $layout, array $packages): ?string
    {
        return $this->packageVersion($framework, $packages['installed'])
            ?? $this->sourceVersion($framework, $layout)
            ?? $this->packageVersion($framework, $packages['locked']);
    }

    /**
     * @param array<string, string> $packages
     */
    private function packageVersion(Framework $framework, array $packages): ?string
    {
        foreach ($framework->versionPackages() as $package) {
            $version = isset($packages[$package]) ? $this->normalizeVersion($packages[$package]) : null;
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param Layout $layout
     */
    private function sourceVersion(Framework $framework, array $layout): ?string
    {
        $pattern = $framework === Framework::WordPress ? self::WORDPRESS_VERSION_PATTERN : self::VERSION_CONSTANT_PATTERN;

        foreach ($this->versionFiles($framework, $layout) as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $source = file_get_contents($file, false, null, 0, self::MAX_SOURCE_BYTES);
            if ($source !== false && preg_match($pattern, $source, $matches) === 1) {
                $version = $this->normalizeVersion($matches[1]);
                if ($version !== null) {
                    return $version;
                }
            }
        }

        return null;
    }

    /**
     * Source files that hold the framework's version constant, in the classic and Composer layouts.
     *
     * @param Layout $layout
     *
     * @return list<string>
     */
    private function versionFiles(Framework $framework, array $layout): array
    {
        $vendorDir = $layout['vendor_dir'];
        $wordpressDir = $layout['wordpress_dir'];
        $roots = $layout['roots'];

        $files = match ($framework) {
            Framework::Laravel => [$vendorDir . '/laravel/framework/src/Illuminate/Foundation/Application.php'],
            Framework::Symfony => [$vendorDir . '/symfony/http-kernel/Kernel.php'],
            Framework::Drupal => $this->inRoots($roots, [
                'core/lib/Drupal.php',
                'web/core/lib/Drupal.php',
                'docroot/core/lib/Drupal.php',
            ]),
            Framework::Typo3 => [
                $vendorDir . '/typo3/cms-core/Classes/Information/Typo3Version.php',
                ...$this->inRoots($roots, [
                    'typo3/sysext/core/Classes/Information/Typo3Version.php',
                    'public/typo3/sysext/core/Classes/Information/Typo3Version.php',
                ]),
            ],
            Framework::WordPress => [
                ...($wordpressDir === null ? [] : [$wordpressDir . '/wp-includes/version.php']),
                ...$this->inRoots($roots, [
                    'wp-includes/version.php',
                    'wp/wp-includes/version.php',
                    'wordpress/wp-includes/version.php',
                    'web/wp/wp-includes/version.php',
                ]),
            ],
        };

        return array_values(array_unique($files));
    }

    /**
     * Each path under each root.
     *
     * @param list<string> $roots
     * @param list<string> $paths relative, without leading slash
     *
     * @return list<string>
     */
    private function inRoots(array $roots, array $paths): array
    {
        $files = [];
        foreach ($roots as $root) {
            foreach ($paths as $path) {
                $files[] = $root . '/' . $path;
            }
        }

        return $files;
    }

    /**
     * A missing file is normal and silent; an unreadable, oversized or invalid one is logged.
     *
     * @return null|array<mixed>
     */
    private function readJson(string $app, string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $size = filesize($file);
        $content = is_readable($file) && $size !== false && $size <= self::MAX_JSON_BYTES
            ? file_get_contents($file)
            : false;
        if ($content === false) {
            $this->logger->warning('Framework detection skipped an unreadable file', ['app' => $app, 'file' => $file]);

            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->logger->warning('Framework detection skipped invalid JSON', ['app' => $app, 'file' => $file]);

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * A configured path made absolute against `$base`, without trailing slash; `null` when unset or blank.
     */
    private function resolvePath(mixed $path, string $base): ?string
    {
        return is_string($path) && trim($path) !== ''
            ? rtrim($this->tools->normalizePath($path, $base), '/')
            : null;
    }

    /**
     * A concrete release such as `6.8.3`, `v7.1.9` or `11.0.0-RC1`; `null` for branches like
     * `dev-main` or `12.x-dev`, which name no version.
     */
    private function normalizeVersion(string $version): ?string
    {
        return strlen($version) <= self::MAX_VERSION_LENGTH && preg_match(self::VERSION_PATTERN, trim($version), $matches) === 1
            ? $matches[1]
            : null;
    }
}
