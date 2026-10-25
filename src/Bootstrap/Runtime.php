<?php

declare(strict_types=1);

namespace Mata\Bootstrap;

use Mata\Config\DatabasesConfigParser;
use Mata\Config\Dto\EnvConfig;
use Mata\Config\Dto\StartupConfigResult;
use Mata\Config\StartupConfigException;
use Mata\Config\StartupConfigInventory;
use Mata\Config\StartupConfigManager;
use Mata\Services\Command\NativeCommandRunner;
use Mata\Services\FileCache;
use Mata\Utils\PathResolver;
use Mata\Utils\Tools;
use Mata\Watchers\AppWatcher;
use Mata\Watchers\DatabaseWatcher;
use Mata\Watchers\ServerWatcher;
use Psr\Log\LoggerInterface;

/**
 * The booted runtime: resolved paths, validated config and the watchers built from them. Shared by
 * public/index.php and bin/mata-node. Booting writes nothing unless missing config files are
 * created from templates; the web entry point creates the logs and temp directories itself.
 */
final readonly class Runtime
{
    private function __construct(
        public PathResolver $paths,
        public EnvConfig $env,
        private StartupConfigResult $config,
    ) {}

    /**
     * @param bool $createMissingConfig Create missing config files from templates, as the web entry point does
     *
     * @throws StartupConfigException When config files are missing or invalid
     */
    public static function boot(PathResolver $paths, LoggerInterface $logger, bool $createMissingConfig = true): self
    {
        $configDir = $paths->appPath . '/config';
        $config = (new StartupConfigManager(
            configDir: $configDir,
            exampleDir: $configDir . '/example',
            envFiles: StartupConfigInventory::ENV_FILES,
            jsonFiles: StartupConfigInventory::JSON_FILES,
            logger: $logger,
            createMissing: $createMissingConfig,
        ))->initialize();

        $env = $config->envConfig;

        return new self($paths->withOverrides($env->logging->path, $env->environment->tempDir), $env, $config);
    }

    public function appWatcher(LoggerInterface $logger): AppWatcher
    {
        return new AppWatcher(
            new Tools(),
            $logger,
            $this->paths->rootPath,
            $this->config->jsonConfig('apps.json'),
            $this->config->jsonConfig('transmit.json'),
            fileCache: new FileCache($this->paths->cachePath),
            commandRunner: new NativeCommandRunner(),
            composerHome: $this->paths->cachePath . '/composer',
            composerBin: $this->env->composer->bin,
            composerPhpBin: $this->env->composer->phpBin,
        );
    }

    public function serverWatcher(): ServerWatcher
    {
        return new ServerWatcher(
            $this->paths->rootPath,
            new FileCache($this->paths->cachePath),
            $this->config->jsonConfig('transmit.json'),
            new NativeCommandRunner(),
        );
    }

    /**
     * @throws StartupConfigException When databases.json is invalid
     */
    public function databaseWatcher(LoggerInterface $logger): DatabaseWatcher
    {
        $monitoring = (new DatabasesConfigParser())->parse($this->config->jsonConfig('databases.json'));

        return new DatabaseWatcher($monitoring, logger: $logger);
    }
}
