<?php

declare(strict_types=1);

namespace Mata\Watchers\App;

/**
 * Versioned, opaque fingerprint of an application's current fatal observation.
 *
 * Consumers compare cursors for equality only. The value never exposes log
 * content, filesystem paths, file identity, offsets, or per-entry hashes.
 */
final class FatalCursor
{
    public const VERSION = 'v1';

    private function __construct() {}

    /**
     * Build the app cursor from per-file fatal observations.
     *
     * Observations are canonicalized and sorted, so the result does not depend
     * on configuration map iteration order.
     *
     * @param array<int, array{path: string, parser: string, digest: string}> $observations
     */
    public static function fromObservations(array $observations): ?string
    {
        if ($observations === []) {
            return null;
        }

        $canonical = array_map(
            static fn (array $observation): string => $observation['path']
                . "\0" . $observation['parser']
                . "\0" . $observation['digest'],
            $observations
        );
        sort($canonical, SORT_STRING);

        return self::VERSION . ':' . hash('sha256', self::VERSION . "\n" . implode("\n", $canonical));
    }

    public static function isValid(string $cursor): bool
    {
        return preg_match('/^' . self::VERSION . ':[0-9a-f]{64}$/', $cursor) === 1;
    }
}
