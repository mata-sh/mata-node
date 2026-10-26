<?php

declare(strict_types=1);

namespace Mata\Tests\Integration;

use Mata\Cli\CliApplication;
use Mata\Cli\Command\ConfigValidateCommand;
use Mata\Cli\Command\LogTestCommand;
use Mata\Config\Validation\NodeConfigValidator;
use Mata\Utils\PathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * bin/mata-node commands against a node tree in a temp directory: `<root>/node` is the node,
 * `<root>/shop` a watched app.
 *
 * @internal
 *
 * @coversNothing
 */
final class NodeCliTest extends TestCase
{
    private string $root;
    private string $configDir;

    /** @var array<string, mixed> */
    private array $env;

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        // Dotenv writes config.env into $_ENV and $_SERVER; restore both for later tests.
        $this->env = $_ENV;
        $this->server = $_SERVER;

        $this->root = sys_get_temp_dir() . '/mata-node-cli-' . bin2hex(random_bytes(6));
        $this->configDir = $this->root . '/node/config';
        mkdir($this->root . '/node/bin', 0775, true);
        mkdir($this->configDir . '/example', 0775, true);
        mkdir($this->root . '/shop/logs', 0775, true);
        touch($this->root . '/node/bin/mata-node');
        copy(__DIR__ . '/../Fixtures/logs/monolog.log', $this->root . '/shop/logs/app.log');

        file_put_contents($this->configDir . '/config.env', "DASHBOARD_API_KEY=test-key\nIP_WHITELIST_ENABLED=OFF\n");
        file_put_contents($this->configDir . '/databases.json', '{}');
        file_put_contents($this->configDir . '/transmit.json', '{}');
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        $_SERVER = $this->server;

        $this->removeDirectory($this->root);
    }

    public function testConfigValidateReportsEveryProblemWithoutCreatingMissingFiles(): void
    {
        unlink($this->configDir . '/transmit.json');
        file_put_contents($this->configDir . '/example/transmit.json.example', '{}');
        $this->writeApps([
            'Shop' => $this->app(['monitoring' => ['logsizes' => false]]),
            'blog' => $this->app(['logs' => [['type' => 'laravel', 'paths' => ['shop/logs/app.log']]]]),
        ]);

        [$status, $report] = $this->runJson('config:validate');

        $this->assertSame(2, $status);
        $this->assertSame('error', $report['status']);
        $this->assertSame([
            ['file' => 'config.env', 'status' => 'ok'],
            ['file' => 'apps.json', 'status' => 'error'],
            ['file' => 'databases.json', 'status' => 'ok'],
            ['file' => 'transmit.json', 'status' => 'error'],
        ], $report['files']);
        $this->assertSame([
            ['transmit.json', null, null, 'error'],
            ['apps.json', 'Shop', null, 'error'],
            ['apps.json', 'Shop', 'monitoring.logsizes', 'error'],
            ['apps.json', 'blog', 'logs[0].type', 'warning'],
        ], array_map(
            static fn (array $problem): array => [$problem['file'], $problem['app'], $problem['field'], $problem['level']],
            $report['problems'],
        ));
        $this->assertFileDoesNotExist($this->configDir . '/transmit.json');
    }

    public function testConfigValidateOnlyWarnsAboutSettingsTheNodeSkips(): void
    {
        $this->writeApps(['shop' => $this->app(['logs' => ['', 'shop/logs/app.log'], 'disabled' => 'yes'])]);

        [$status, $report] = $this->runJson('config:validate');

        $this->assertSame(1, $status);
        $this->assertSame([
            ['file' => 'config.env', 'status' => 'ok'],
            ['file' => 'apps.json', 'status' => 'warning'],
            ['file' => 'databases.json', 'status' => 'ok'],
            ['file' => 'transmit.json', 'status' => 'ok'],
        ], $report['files']);
        $this->assertSame(['disabled', 'logs[0]'], array_column($report['problems'], 'field'));
    }

    public function testConfigValidateJsonDoesNotEscapeUnicode(): void
    {
        $this->writeApps(['shop' => $this->app(['logs' => [
            ['type' => 'échec', 'paths' => ['shop/logs/app.log']],
        ]])]);
        $paths = PathResolver::fromEntryPoint($this->root . '/node/bin/mata-node');
        $tester = new CommandTester(new ConfigValidateCommand(new NodeConfigValidator(), $paths));

        $status = $tester->execute(['--json' => true]);
        $json = $tester->getDisplay();
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $status);
        $this->assertSame(1, $report['schema_version']);
        $this->assertStringContainsString('échec', $report['problems'][0]['message']);
        $this->assertStringContainsString('échec', $json);
        $this->assertStringNotContainsString('\u00e9', $json);
    }

    public function testLogTestFlagsATypeThatParsesNoEntries(): void
    {
        $this->writeApps(['shop' => $this->app(['logs' => [
            ['type' => 'monolog', 'paths' => ['shop/logs/app.log']],
            ['type' => 'json', 'paths' => ['shop/logs/app.log']],
        ]])]);

        [$status, $result] = $this->runJson('log:test', ['app' => 'shop', '--limit' => '2']);

        $this->assertSame(1, $status);
        [$monolog, $json] = $result['files'];
        $this->assertSame('ok', $monolog['status']);
        $this->assertSame('monolog', $monolog['parser']);
        $this->assertCount(2, $monolog['samples']);
        $this->assertGreaterThan(0, $monolog['fatal']);
        $this->assertSame('warning', $json['status']);
        $this->assertSame(0, $json['entries']);
        $this->assertStringContainsString('does not match the format', $json['problems'][0]);
    }

    public function testLogTestWithLimitZeroShowsNoSamples(): void
    {
        $this->writeApps(['shop' => $this->app(['logs' => ['shop/logs/app.log']])]);

        [$status, $result] = $this->runJson('log:test', ['app' => 'shop', '--limit' => '0']);

        $this->assertSame(0, $status);
        $this->assertSame('monolog', $result['files'][0]['parser']);
        $this->assertSame([], $result['files'][0]['samples']);
    }

    public function testLogTestRefusesToCreateMissingConfigFiles(): void
    {
        $this->writeApps(['shop' => $this->app()]);
        unlink($this->configDir . '/transmit.json');
        file_put_contents($this->configDir . '/example/transmit.json.example', '{}');

        [$status, $result] = $this->runJson('log:test', ['app' => 'shop']);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('config validate', $result['error']);
        $this->assertFileDoesNotExist($this->configDir . '/transmit.json');
    }

    public function testLogTestParsesWithAFormatFromTheConfigDirectory(): void
    {
        $this->writePlainLevelFormat();
        file_put_contents($this->root . '/shop/logs/plain.log', "2026-10-26T08:00:00Z ERROR Payment failed\n2026-10-26T08:00:05Z WARN Retrying\n");
        $this->writeApps(['shop' => $this->app(['logs' => [['type' => 'plain_level', 'paths' => ['shop/logs/plain.log']]]])]);

        [$status, $result] = $this->runJson('log:test', ['app' => 'shop']);

        $this->assertSame(0, $status);
        $file = $result['files'][0];
        $this->assertSame('plain_level', $file['parser']);
        $this->assertTrue($file['type_known']);
        $this->assertSame([2, 1, 1], [$file['entries'], $file['fatal'], $file['warnings']]);
    }

    public function testConfigValidateListsFormatFilesAndOnlyWarnsAboutABrokenOne(): void
    {
        $this->writePlainLevelFormat();
        file_put_contents($this->configDir . '/log-formats/broken.yaml', "pattern: '^(?<message>.*)$'\n");
        $this->writeApps(['shop' => $this->app(['logs' => [['type' => 'plain_level', 'paths' => ['shop/logs/app.log']]]])]);

        [$status, $report] = $this->runJson('config:validate');

        $this->assertSame(1, $status);
        $this->assertSame([
            ['file' => 'config.env', 'status' => 'ok'],
            ['file' => 'apps.json', 'status' => 'ok'],
            ['file' => 'databases.json', 'status' => 'ok'],
            ['file' => 'transmit.json', 'status' => 'ok'],
            ['file' => 'log-formats/broken.yaml', 'status' => 'warning'],
            ['file' => 'log-formats/plain_level.yaml', 'status' => 'ok'],
        ], $report['files']);
        $this->assertCount(1, $report['problems']);
    }

    public function testUsageErrorIsJsonWithJsonOption(): void
    {
        $application = new CliApplication();
        $application->setAutoExit(false);
        $application->addCommands([new LogTestCommand(PathResolver::fromEntryPoint($this->root . '/node/bin/mata-node'))]);

        $tester = new ApplicationTester($application);
        $status = $tester->run(['command' => 'log:test', '--json' => true]);

        $this->assertSame(2, $status);
        $this->assertSame(
            ['schema_version' => 1, 'status' => 'error', 'error' => 'Not enough arguments (missing: "app").'],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function app(array $overrides = []): array
    {
        return $overrides + ['title' => 'Shop', 'url' => 'https://shop.example', 'path' => 'shop', 'env' => 'prod'];
    }

    private function writePlainLevelFormat(): void
    {
        mkdir($this->configDir . '/log-formats');
        file_put_contents($this->configDir . '/log-formats/plain_level.yaml', <<<'YAML'
            pattern: '^(?<timestamp>\S+Z) (?<level>[A-Z]+) (?<message>.*)$'
            levels:
              error: [ERROR]
              warning: [WARN]
            YAML);
    }

    /**
     * @param array<string, mixed> $apps
     */
    private function writeApps(array $apps): void
    {
        file_put_contents($this->configDir . '/apps.json', json_encode($apps, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{int, array<string, mixed>}
     */
    private function runJson(string $command, array $input = []): array
    {
        $paths = PathResolver::fromEntryPoint($this->root . '/node/bin/mata-node');
        $application = new CliApplication();
        $application->addCommands([
            new ConfigValidateCommand(new NodeConfigValidator(), $paths),
            new LogTestCommand($paths),
        ]);

        $tester = new CommandTester($application->find($command));
        $status = $tester->execute($input + ['--json' => true]);

        return [$status, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)];
    }

    private function removeDirectory(string $path): void
    {
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }

        rmdir($path);
    }
}
