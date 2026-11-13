<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Dto\LogFormatConfig;
use Mata\Config\LogFormatLoader;
use Mata\Watchers\LogParsers\DefinedLogParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\LogParsers\AbstractLogParser
 * @covers \Mata\Watchers\LogParsers\DefinedLogParser
 *
 * @internal
 */
final class DefinedLogParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/logs';

    public function testShippedNginxFormatParsesAnNginxErrorLog(): void
    {
        $formats = (new LogFormatLoader())->load(sys_get_temp_dir() . '/mata-node-no-config')['formats'];
        $parser = new DefinedLogParser($formats['nginx_error']);
        $logFile = self::FIXTURES . '/nginx_error.log';

        $entries = $parser->parseLines(file($logFile, FILE_IGNORE_NEW_LINES) ?: []);

        $this->assertSame(['Other', 'Error', 'Warning', 'Error'], array_column($entries, 'type'));
        $this->assertSame('2026-10-26 08:01:12', $entries[1]['timestamp']);
        $this->assertStringStartsWith('FastCGI sent in stderr: "PHP message: PHP Fatal error:', $entries[1]['message']);
        $this->assertSame(['fatal' => 2, 'warnings' => 1, 'total' => 4], $parser->analyzeLog($logFile)->counts());
    }

    /** Stack traces belong to the entry above them, in /logs and in the fatal counts. */
    public function testLinesThePatternDoesNotMatchContinueTheEntry(): void
    {
        $parser = $this->parser(DefinedLogParser::compile('^(?<timestamp>\S+) (?<level>[A-Z]+) (?<message>.*)$'));
        $lines = [
            '#1 cut off trace of an earlier entry',
            '2026-10-26T08:00:00Z ERROR Payment failed',
            '#0 /src/Pay.php(12): charge()',
            '2026-10-26T08:00:05Z INFO Retried',
        ];

        $entries = $parser->parseLines($lines);
        $counts = $this->analyze($parser, $lines);

        $this->assertSame(["Payment failed\n#0 /src/Pay.php(12): charge()", 'Retried'], array_column($entries, 'message'));
        $this->assertSame(['fatal' => 1, 'warnings' => 0, 'total' => 4], $counts);
    }

    /** Log lines are written by whoever sends requests; none may make the node hang. */
    public function testCatastrophicPatternGivesUpInsteadOfHanging(): void
    {
        $parser = $this->parser(DefinedLogParser::compile('^(?<level>(?:a+)+)x(?<message>.*)$'));
        $line = str_repeat('a', 5000);

        $start = microtime(true);
        $entries = $parser->parseLines([$line, $line]);

        $this->assertSame([], $entries);
        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    public function testJsonFormatReadsNamedAndDottedFields(): void
    {
        $parser = $this->parser(null, ['timestamp' => '@timestamp', 'level' => 'log.level', 'message' => 'message']);
        $lines = [
            '{"@timestamp":"2026-10-26T08:00:00Z","log.level":"error","message":"flat"}',
            '{"@timestamp":"2026-10-26T08:00:01Z","log":{"level":"warn"},"message":"nested"}',
            'not json',
            '{"log.level":"info","message":{"text":"structured"}}',
        ];

        $entries = $parser->parseLines($lines);

        $this->assertSame(['Error', 'Warning', 'Other'], array_column($entries, 'type'));
        $this->assertSame(['flat', 'nested', '{"text":"structured"}'], array_column($entries, 'message'));
        $this->assertSame(['2026-10-26 08:00:00', '2026-10-26 08:00:01', ''], array_column($entries, 'timestamp'));
        $this->assertSame(['fatal' => 1, 'warnings' => 1, 'total' => 4], $this->analyze($parser, $lines));
    }

    /**
     * @param null|array{timestamp: null|string, level: string, message: string} $jsonFields
     */
    private function parser(?string $pattern, ?array $jsonFields = null): DefinedLogParser
    {
        $levels = ['error' => 'Error', 'warn' => 'Warning'];

        return new DefinedLogParser(new LogFormatConfig('test_format', $pattern, $jsonFields, $levels));
    }

    /**
     * @param list<string> $lines
     *
     * @return array{fatal: int, warnings: int, total: int}
     */
    private function analyze(DefinedLogParser $parser, array $lines): array
    {
        $logFile = tempnam(sys_get_temp_dir(), 'mata_defined_');
        self::assertNotFalse($logFile);

        try {
            file_put_contents($logFile, implode("\n", $lines) . "\n");

            return $parser->analyzeLog($logFile)->counts();
        } finally {
            unlink($logFile);
        }
    }
}
