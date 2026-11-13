<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\LogFormatConfig;
use Mata\Watchers\LogParsers\DefinedLogParser;
use Mata\Watchers\LogParsers\JsonParser;
use Mata\Watchers\LogParsers\LogParserFactory;
use Mata\Watchers\LogParsers\MonologParser;
use Mata\Watchers\LogParsers\PhpErrorLogParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\LogParsers\LogParserFactory
 *
 * @internal
 */
final class LogParserFactoryTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/logs';

    public function testNamesAreTheBuiltInParsersThenTheDefinedFormats(): void
    {
        $factory = new LogParserFactory(['nginx_error' => $this->format('nginx_error')]);

        $this->assertSame(['json', 'monolog', 'php_error_log', 'nginx_error'], $factory->names());
        $this->assertInstanceOf(DefinedLogParser::class, $factory->parser('nginx_error'));
        $this->assertInstanceOf(MonologParser::class, $factory->parser('monolog'));
    }

    /** A typo in apps.json must not silently pick another parser. */
    public function testUnknownNamesHaveNoParser(): void
    {
        $factory = new LogParserFactory();

        $this->assertNull($factory->parser('unknown_format'));
        $this->assertNull($factory->parser(''));
        $this->assertNull($factory->parser('PHP_ERROR_LOG'));
    }

    /**
     * @param class-string $parser
     */
    #[DataProvider('detectionProvider')]
    public function testDetectsTheBuiltInFormats(string $file, string $parser): void
    {
        $this->assertInstanceOf($parser, (new LogParserFactory())->detect(self::FIXTURES . '/' . $file));
    }

    public static function detectionProvider(): array
    {
        return [
            'php error log' => ['php_error.log', PhpErrorLogParser::class],
            'monolog' => ['monolog.log', MonologParser::class],
            'json' => ['json.log', JsonParser::class],
            'unknown format falls back' => ['mixed.log', PhpErrorLogParser::class],
            'empty file falls back' => ['empty.log', PhpErrorLogParser::class],
            'missing file falls back' => ['nonexistent.log', PhpErrorLogParser::class],
        ];
    }

    /** A defined format only applies where apps.json names it, so it cannot claim other apps' logs. */
    public function testDefinedFormatsAreNotAutoDetected(): void
    {
        $factory = new LogParserFactory(['nginx_error' => $this->format('nginx_error')]);

        $this->assertInstanceOf(PhpErrorLogParser::class, $factory->detect(self::FIXTURES . '/nginx_error.log'));
    }

    /** A log that was empty when first checked is detected again once it has lines. */
    public function testFallbackIsNotKept(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'mata_log_parser_');
        self::assertNotFalse($logFile);
        $factory = new LogParserFactory();

        try {
            file_put_contents($logFile, '');
            $this->assertInstanceOf(PhpErrorLogParser::class, $factory->detect($logFile));

            $jsonLine = '{"timestamp":"2026-01-01T00:00:00Z","level":"error","message":"x"}';
            file_put_contents($logFile, implode("\n", array_fill(0, 10, $jsonLine)) . "\n");

            $this->assertInstanceOf(JsonParser::class, $factory->detect($logFile));
        } finally {
            unlink($logFile);
        }
    }

    private function format(string $name): LogFormatConfig
    {
        return new LogFormatConfig($name, DefinedLogParser::compile('^(?<level>\w+) (?<message>.*)$'), null, ['error' => 'Error']);
    }
}
