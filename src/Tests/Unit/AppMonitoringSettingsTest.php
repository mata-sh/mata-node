<?php

declare(strict_types=1);

namespace Mata\Tests\Unit;

use InvalidArgumentException;
use Mata\Watchers\App\AppMonitoringSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Watchers\App\AppMonitoringSettings
 *
 * @internal
 */
final class AppMonitoringSettingsTest extends TestCase
{
    public function testSettingsConstantListsTheSixKnownKeys(): void
    {
        $this->assertSame(
            ['loganalysis', 'logsize', 'sessions', 'reachability', 'alarms', 'events'],
            AppMonitoringSettings::SETTINGS
        );
    }

    #[DataProvider('absentMonitoringProvider')]
    public function testAbsentMonitoringEnablesEverything(mixed $monitoring): void
    {
        $parsed = AppMonitoringSettings::parse($monitoring);

        $this->assertNull($parsed['invalid_field']);
        $settings = $parsed['settings'];
        $this->assertInstanceOf(AppMonitoringSettings::class, $settings);

        foreach (AppMonitoringSettings::SETTINGS as $setting) {
            $this->assertTrue($settings->isEnabled($setting), $setting . ' should default to enabled');
        }

        $this->assertSame([], $settings->disabledMap());
    }

    public static function absentMonitoringProvider(): array
    {
        return [
            // json_decode() maps an absent key to null via the caller's `?? null`.
            'null' => [null],
            // An empty JSON object decodes to an empty array.
            'empty object' => [[]],
        ];
    }

    public function testDefaultConstructorEnablesEverything(): void
    {
        $settings = new AppMonitoringSettings();

        $this->assertTrue($settings->loganalysis);
        $this->assertTrue($settings->logsize);
        $this->assertTrue($settings->sessions);
        $this->assertTrue($settings->reachability);
        $this->assertTrue($settings->alarms);
        $this->assertTrue($settings->events);
    }

    #[DataProvider('singleSettingProvider')]
    public function testEachSettingIsIndependent(string $setting): void
    {
        $parsed = AppMonitoringSettings::parse([$setting => false]);

        $this->assertNull($parsed['invalid_field']);
        $settings = $parsed['settings'];
        $this->assertInstanceOf(AppMonitoringSettings::class, $settings);

        $this->assertFalse($settings->isEnabled($setting));
        foreach (AppMonitoringSettings::SETTINGS as $other) {
            if ($other === $setting) {
                continue;
            }

            $this->assertTrue($settings->isEnabled($other), $other . ' must stay enabled');
        }

        $this->assertSame([$setting => false], $settings->disabledMap());
    }

    public static function singleSettingProvider(): array
    {
        $cases = [];
        foreach (AppMonitoringSettings::SETTINGS as $setting) {
            $cases[$setting] = [$setting];
        }

        return $cases;
    }

    public function testAllSettingsCanBeDisabledTogether(): void
    {
        $parsed = AppMonitoringSettings::parse([
            'events' => false,
            'alarms' => false,
            'reachability' => false,
            'sessions' => false,
            'logsize' => false,
            'loganalysis' => false,
        ]);

        $settings = $parsed['settings'];
        $this->assertInstanceOf(AppMonitoringSettings::class, $settings);

        // disabledMap() is ordered by SETTINGS, not by input order.
        $this->assertSame([
            'loganalysis' => false,
            'logsize' => false,
            'sessions' => false,
            'reachability' => false,
            'alarms' => false,
            'events' => false,
        ], $settings->disabledMap());
    }

    public function testDisabledMapIsSparseAndOrderedBySettings(): void
    {
        $parsed = AppMonitoringSettings::parse([
            'events' => false,
            'logsize' => false,
            'loganalysis' => true,
        ]);

        $settings = $parsed['settings'];
        $this->assertInstanceOf(AppMonitoringSettings::class, $settings);
        $this->assertSame(['logsize' => false, 'events' => false], $settings->disabledMap());
    }

    public function testExplicitTrueIsAcceptedAndOmittedFromDisabledMap(): void
    {
        $parsed = AppMonitoringSettings::parse([
            'loganalysis' => true,
            'logsize' => true,
            'sessions' => true,
            'reachability' => true,
            'alarms' => true,
            'events' => true,
        ]);

        $this->assertNull($parsed['invalid_field']);
        $this->assertSame([], $parsed['settings']?->disabledMap());
    }

    #[DataProvider('invalidSettingValueProvider')]
    public function testNonBooleanSettingValueIsInvalid(string $setting, mixed $value): void
    {
        $parsed = AppMonitoringSettings::parse([$setting => $value]);

        $this->assertNull($parsed['settings']);
        $this->assertSame('monitoring.' . $setting, $parsed['invalid_field']);
    }

    public static function invalidSettingValueProvider(): array
    {
        $values = [
            'string true' => 'true',
            'string false' => 'false',
            'string on' => 'on',
            'string off' => 'off',
            'empty string' => '',
            'integer one' => 1,
            'integer zero' => 0,
            'float' => 1.0,
            'null' => null,
            'list array' => ['enabled'],
            'empty array' => [],
            // json_decode() gives associative arrays, so this is how a nested JSON
            // object actually reaches the watcher config.
            'json object' => ['enabled' => true],
            'stdclass object' => (object) ['enabled' => true],
        ];

        $cases = [];
        foreach (AppMonitoringSettings::SETTINGS as $setting) {
            foreach ($values as $label => $value) {
                $cases[$setting . ' ' . $label] = [$setting, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('nonObjectMonitoringProvider')]
    public function testNonObjectMonitoringIsInvalid(mixed $monitoring): void
    {
        $parsed = AppMonitoringSettings::parse($monitoring);

        $this->assertNull($parsed['settings']);
        $this->assertSame('monitoring', $parsed['invalid_field']);
    }

    public static function nonObjectMonitoringProvider(): array
    {
        return [
            'string' => ['loganalysis'],
            'empty string' => [''],
            'true' => [true],
            'false' => [false],
            'integer' => [1],
            'float' => [1.5],
            // A JSON array decodes to a PHP list.
            'list of strings' => [['loganalysis', 'logsize']],
            'list of booleans' => [[true, false]],
            'stdclass object' => [(object) ['loganalysis' => false]],
        ];
    }

    #[DataProvider('unknownKeyProvider')]
    public function testUnknownKeyIsInvalid(array $monitoring, string $expectedField): void
    {
        $parsed = AppMonitoringSettings::parse($monitoring);

        $this->assertNull($parsed['settings']);
        $this->assertSame($expectedField, $parsed['invalid_field']);
    }

    public static function unknownKeyProvider(): array
    {
        return [
            // A typo must not silently resolve to enabled.
            'typo' => [['logsizes' => false], 'monitoring.logsizes'],
            'legacy flat flag' => [['error_monitoring_enabled' => false], 'monitoring.error_monitoring_enabled'],
            'legacy ping' => [['ping' => false], 'monitoring.ping'],
            'unknown with valid bool' => [['diskspace' => true], 'monitoring.diskspace'],
            'unknown alongside known' => [['logsize' => false, 'metrics' => false], 'monitoring.metrics'],
            'first unknown in input order' => [['metrics' => true, 'diskspace' => true], 'monitoring.metrics'],
            'empty key' => [['' => false], 'monitoring.'],
        ];
    }

    public function testKnownKeyValueErrorIsReportedBeforeUnknownKey(): void
    {
        $parsed = AppMonitoringSettings::parse(['metrics' => true, 'logsize' => 'false']);

        $this->assertSame('monitoring.logsize', $parsed['invalid_field']);
    }

    public function testKnownKeyValueErrorsAreReportedInSettingsOrder(): void
    {
        $parsed = AppMonitoringSettings::parse(['events' => 'no', 'logsize' => 'no']);

        $this->assertSame('monitoring.logsize', $parsed['invalid_field']);
    }

    public function testIsEnabledRejectsUnknownSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown monitoring setting: diskspace');

        (new AppMonitoringSettings())->isEnabled('diskspace');
    }
}
