<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

use InvalidArgumentException;

/**
 * Per-application monitoring switches declared in `apps.json` under `monitoring`.
 *
 * Every setting defaults to enabled, so an app without a `monitoring` block keeps
 * behaving exactly as before the block existed. Parsing is pure and never throws;
 * the caller owns logging and the invalid-configuration response.
 */
final readonly class AppMonitoringSettings
{
    /**
     * The single source of truth for accepted keys and their output order.
     */
    public const SETTINGS = ['loganalysis', 'logsize', 'sessions', 'reachability', 'alarms', 'events', 'libraryaudit'];

    public function __construct(
        public bool $loganalysis = true,
        public bool $logsize = true,
        public bool $sessions = true,
        public bool $reachability = true,
        public bool $alarms = true,
        public bool $events = true,
        public bool $libraryaudit = true,
    ) {}

    /**
     * Parse a raw `monitoring` value.
     *
     * Absent or `null` means every setting is enabled. Only literal JSON booleans
     * are accepted; every other representation and every unknown key is an invalid
     * application configuration reported through `invalid_field`.
     *
     * @return array{settings: ?self, invalid_field: ?string}
     */
    public static function parse(mixed $monitoring): array
    {
        if ($monitoring === null) {
            return ['settings' => new self(), 'invalid_field' => null];
        }

        // A JSON object decodes to an associative array; an empty object and an empty
        // array are indistinguishable, so `[]` is accepted as "no keys set".
        if (!is_array($monitoring) || ($monitoring !== [] && array_is_list($monitoring))) {
            return ['settings' => null, 'invalid_field' => 'monitoring'];
        }

        $values = [];
        foreach (self::SETTINGS as $setting) {
            if (!array_key_exists($setting, $monitoring)) {
                $values[$setting] = true;

                continue;
            }

            if (!is_bool($monitoring[$setting])) {
                return ['settings' => null, 'invalid_field' => 'monitoring.' . $setting];
            }

            $values[$setting] = $monitoring[$setting];
        }

        foreach (array_keys($monitoring) as $key) {
            if (!in_array($key, self::SETTINGS, true)) {
                return ['settings' => null, 'invalid_field' => 'monitoring.' . $key];
            }
        }

        return [
            'settings' => new self(
                loganalysis: $values['loganalysis'],
                logsize: $values['logsize'],
                sessions: $values['sessions'],
                reachability: $values['reachability'],
                alarms: $values['alarms'],
                events: $values['events'],
                libraryaudit: $values['libraryaudit'],
            ),
            'invalid_field' => null,
        ];
    }

    public function isEnabled(string $setting): bool
    {
        return match ($setting) {
            'loganalysis' => $this->loganalysis,
            'logsize' => $this->logsize,
            'sessions' => $this->sessions,
            'reachability' => $this->reachability,
            'alarms' => $this->alarms,
            'events' => $this->events,
            'libraryaudit' => $this->libraryaudit,
            default => throw new InvalidArgumentException('Unknown monitoring setting: ' . $setting),
        };
    }

    /**
     * Disabled settings only, in `SETTINGS` order, for the sparse `/apps` payload.
     *
     * @return array<string, false>
     */
    public function disabledMap(): array
    {
        $disabled = [];
        foreach (self::SETTINGS as $setting) {
            if (!$this->isEnabled($setting)) {
                $disabled[$setting] = false;
            }
        }

        return $disabled;
    }
}
