<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\LogFormatLoader;
use Mata\Config\Validation\ConfigProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Config\LogFormatLoader
 *
 * @internal
 */
final class LogFormatLoaderTest extends TestCase
{
    private const VALID = <<<'YAML'
        pattern: '^(?<timestamp>\S+) (?<level>[A-Z]+) (?<message>.*)$'
        levels:
          error: [ERROR, FATAL]
          warning: [WARN]
        YAML;

    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/mata-node-formats-' . bin2hex(random_bytes(6));
        mkdir($this->configDir . '/log-formats', 0775, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->configDir . '/log-formats/*') ?: []);
        rmdir($this->configDir . '/log-formats');
        rmdir($this->configDir);
    }

    /** A pull request adding a built-in format must not ship a file the node skips. */
    public function testBuiltInFormatsLoadWithoutProblems(): void
    {
        rmdir($this->configDir . '/log-formats');

        $result = (new LogFormatLoader())->load($this->configDir);
        mkdir($this->configDir . '/log-formats');

        $this->assertSame([], array_map(static fn (ConfigProblem $problem): string => "{$problem->file}: {$problem->message}", $result['problems']));
        $this->assertArrayHasKey('nginx_error', $result['formats']);
        $this->assertSame([], $result['files']);
    }

    public function testInstanceFormatsLoadNextToTheBuiltInOnes(): void
    {
        $this->writeFormat('plain_level.yaml', self::VALID);

        $result = (new LogFormatLoader())->load($this->configDir);

        $format = $result['formats']['plain_level'];
        $this->assertSame('Error', $format->type('fatal'));
        $this->assertSame('Warning', $format->type(' warn '));
        $this->assertSame('Other', $format->type('INFO'));
        $this->assertArrayHasKey('nginx_error', $result['formats']);
        $this->assertSame(['log-formats/plain_level.yaml'], $result['files']);
        $this->assertSame([], $result['problems']);
    }

    public function testYmlExtensionDefinesAFormatToo(): void
    {
        $this->writeFormat('plain_level.yml', self::VALID);

        $result = (new LogFormatLoader())->load($this->configDir);

        $this->assertArrayHasKey('plain_level', $result['formats']);
        $this->assertSame(['log-formats/plain_level.yml'], $result['files']);
        $this->assertSame([], $result['problems']);
    }

    /** One broken file skips only its own format. */
    #[DataProvider('mistakeProvider')]
    public function testReportsTheMistakeAndSkipsTheFormat(string $file, string $yaml, string $message): void
    {
        $this->writeFormat($file, $yaml);
        $this->writeFormat('plain_level.yaml', self::VALID);

        $result = (new LogFormatLoader())->load($this->configDir);

        $this->assertCount(1, $result['problems']);
        $this->assertSame("log-formats/{$file}", $result['problems'][0]->file);
        $this->assertStringContainsString($message, $result['problems'][0]->message);
        $this->assertSame('warning', $result['problems'][0]->level->label());
        $this->assertArrayHasKey('plain_level', $result['formats']);
        $this->assertArrayNotHasKey('broken', $result['formats']);
    }

    public static function mistakeProvider(): array
    {
        $levels = "\nlevels:\n  error: [ERROR]";

        return [
            'typo in a key' => ['broken.yaml', str_replace('levels:', 'level:', self::VALID), 'Unknown key level'],
            'invalid YAML' => ['broken.yaml', "pattern: '^(?<level>x\nlevels: [", 'Invalid YAML'],
            'neither pattern nor json' => ['broken.yaml', 'description: x' . $levels, 'Set either pattern'],
            'both pattern and json' => ['broken.yaml', "pattern: '(?<level>x)(?<message>y)'\njson: {level: l, message: m}" . $levels, 'Set either pattern'],
            'invalid pattern' => ['broken.yaml', "pattern: '^(?<level>x(?<message>.*)$'" . $levels, 'not a valid regular expression'],
            'pattern without message group' => ['broken.yaml', "pattern: '^(?<level>\\w+) (.*)$'" . $levels, '(?<message>'],
            'json without level field' => ['broken.yaml', 'json: {message: msg}' . $levels, 'json.level'],
            'no levels' => ['broken.yaml', "pattern: '^(?<level>\\w+) (?<message>.*)$'", 'levels must list'],
            'level that is both' => ['broken.yaml', "json: {level: l, message: m}\nlevels:\n  error: [ERROR]\n  warning: [error]", 'both error and warning'],
            'file name with a dash' => ['plain-level.yaml', self::VALID, 'lowercase letters, digits and underscores'],
            'built-in name' => ['monolog.yaml', self::VALID, 'already a built-in format'],
            'name of a shipped definition' => ['nginx_error.yaml', self::VALID, 'already a built-in format'],
            'other extension' => ['broken.txt', self::VALID, 'only <name>.yaml or <name>.yml files'],
            'name in .yaml and .yml' => ['plain_level.yml', self::VALID, 'plain_level.yaml already defines "plain_level"'],
        ];
    }

    private function writeFormat(string $file, string $yaml): void
    {
        file_put_contents("{$this->configDir}/log-formats/{$file}", $yaml);
    }
}
