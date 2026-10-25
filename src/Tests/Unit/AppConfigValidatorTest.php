<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use Mata\Config\Validation\AppConfigValidator;
use Mata\Config\Validation\ConfigProblem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mistakes seen in real apps.json files. Metadata parsing itself is covered by AppMetadataTest.
 *
 * @covers \Mata\Config\Validation\AppConfigValidator
 *
 * @internal
 */
final class AppConfigValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('mistakeProvider')]
    public function testReportsTheMistake(array $overrides, string $field, string $level): void
    {
        $app = $overrides + ['title' => 'Shop', 'url' => 'https://shop.example', 'path' => __DIR__, 'env' => 'prod'];

        $problems = (new AppConfigValidator())->validate('shop', $app, __DIR__);

        $this->assertSame(
            [[$field, $level]],
            array_map(static fn (ConfigProblem $problem): array => [$problem->field, $problem->level->label()], $problems),
        );
    }

    public static function mistakeProvider(): array
    {
        return [
            'env spelled out' => [['env' => 'production'], 'env', 'error'],
            'path not deployed' => [['path' => 'does-not-exist'], 'path', 'warning'],
            'logs as a single path' => [['logs' => 'storage/logs/laravel.log'], 'logs', 'warning'],
            'unsupported session backend' => [['sessions' => ['type' => 'memcached']], 'sessions.type', 'warning'],
        ];
    }
}
