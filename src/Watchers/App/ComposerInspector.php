<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

use JsonException;
use Mata\Services\Command\ProcessCommandRunnerInterface;
use Mata\Services\Command\ShellCommands;
use Mata\Utils\Tools;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ComposerInspector
{
    private const COMPOSER_AUDIT_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly Tools $tools,
        private readonly LoggerInterface $logger,
        private readonly string $rootPath,
        private readonly ProcessCommandRunnerInterface $processRunner,
        private readonly ?string $composerHome = null,
    ) {}

    public function resolvePaths(array $config): ?array
    {
        $binPath = null;
        $jsonPath = null;
        $lockPath = null;

        $composerConfig = $config['composer'] ?? null;
        if (is_array($composerConfig)) {
            $jsonPath = $composerConfig['json'] ?? null;
            $lockPath = $composerConfig['lock'] ?? null;
            $binPath = $composerConfig['bin'] ?? null;
        }

        if ($jsonPath === null || $lockPath === null) {
            $autoEnabled = $this->isAutoEnabled($composerConfig);
            if (!$autoEnabled) {
                return null;
            }

            $basePath = $config['path'] ?? null;
            if (!$basePath) {
                return null;
            }

            $jsonPath ??= rtrim($basePath, '/') . '/composer.json';
            $lockPath ??= rtrim($basePath, '/') . '/composer.lock';
        }

        if ($jsonPath === null || $lockPath === null) {
            return null;
        }

        return [
            'json' => $jsonPath ? $this->tools->normalizePath($jsonPath, $this->rootPath) : null,
            'lock' => $lockPath ? $this->tools->normalizePath($lockPath, $this->rootPath) : null,
            'bin' => $binPath ? $this->tools->normalizePath($binPath, $this->rootPath) : null,
        ];
    }

    public function audit(array $paths): array
    {
        $jsonPath = $paths['json'] ?? null;
        $lockPath = $paths['lock'] ?? null;
        $binPath = $paths['bin'] ?? null;

        if (!$jsonPath || !is_readable($jsonPath)) {
            return [
                'status' => 'error',
                'message' => "composer.json not found or unreadable: {$jsonPath}",
            ];
        }

        if (!$lockPath || !is_readable($lockPath)) {
            return [
                'status' => 'error',
                'message' => "composer.lock not found or unreadable: {$lockPath}",
            ];
        }

        $workingDir = dirname($jsonPath);
        $cmd = ShellCommands::composerAudit($binPath, $workingDir, $this->composerHome);

        $processResult = $this->processRunner->run($cmd, self::COMPOSER_AUDIT_TIMEOUT_SECONDS);
        $stdout = trim($processResult->stdout);
        $stderr = trim($processResult->stderr);

        if ($processResult->timedOut) {
            return [
                'status' => 'error',
                'exit_code' => $processResult->exitCode,
                'message' => 'composer audit timed out',
                'stderr' => $stderr,
            ];
        }

        if ($processResult->outputTruncated) {
            return [
                'status' => 'error',
                'exit_code' => $processResult->exitCode,
                'message' => 'composer audit output exceeded maximum size',
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        $data = $stdout !== '' ? json_decode($stdout, true) : null;

        if (is_array($data)) {
            $result = [
                'status' => 'ok',
                'exit_code' => $processResult->exitCode,
                'audit' => $data,
            ];

            if ($stderr !== '') {
                $result['stderr'] = $stderr;
            }

            return $result;
        }

        $this->logger->warning('Composer audit returned invalid JSON', [
            'command' => $cmd,
            'composer_json' => $jsonPath,
            'composer_lock' => $lockPath,
            'composer_home' => $this->composerHome,
            'exit_code' => $processResult->exitCode,
            'json_error' => json_last_error_msg(),
            'stdout_preview' => substr($stdout, 0, 1000),
            'stderr_preview' => substr($stderr, 0, 1000),
        ]);

        return [
            'status' => 'error',
            'exit_code' => $processResult->exitCode,
            'message' => 'composer audit did not return valid JSON',
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Return all libraries from a composer.lock.
     *
     * @param string $lockFile Path to composer.lock
     *
     * @return array<int, array{name: string, version: string, type: string, description: string}>
     *
     * @throws RuntimeException If file unreadable or JSON invalid
     * @throws JsonException
     */
    public function librariesToArray(string $lockFile): array
    {
        if (!is_readable($lockFile)) {
            throw new RuntimeException("composer.lock not found or unreadable: {$lockFile}");
        }

        $content = file_get_contents($lockFile);
        if ($content === false) {
            throw new RuntimeException("composer.lock not readable: {$lockFile}");
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $packages = array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []);

        return array_map(static fn (array $p) => [
            'name' => $p['name'] ?? '',
            'version' => $p['version'] ?? '',
            'type' => $p['type'] ?? '',
            'description' => $p['description'] ?? '',
        ], $packages);
    }

    private function isAutoEnabled(mixed $composerConfig): bool
    {
        if ($composerConfig === true) {
            return true;
        }

        if (is_string($composerConfig)) {
            return $this->tools->isTruthy($composerConfig);
        }

        return false;
    }
}
