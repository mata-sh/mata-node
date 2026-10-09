<?php

declare(strict_types=1);

namespace Mata\Watchers\Database;

/**
 * Engine-neutral filtering, grouping, sorting and truncation of session rows.
 */
final class ProcessList
{
    /**
     * @param list<ProcessRow> $rows       All sessions returned by the driver
     * @param list<string>     $schemas    Configured schema allowlist
     * @param null|string      $database   Restrict to one allowlisted schema
     * @param bool             $activeOnly Keep only running sessions in `processes`
     * @param int              $limit      Maximum number of returned rows
     *
     * @return array{
     *     summary_by_database: list<array{database: string, connections: int, running: int}>,
     *     processes: list<array<string, mixed>>,
     *     truncated: bool
     * }
     */
    public static function build(array $rows, array $schemas, ?string $database, bool $activeOnly, int $limit): array
    {
        $scope = $database === null ? $schemas : [$database];

        $rows = array_values(array_filter(
            $rows,
            static fn (ProcessRow $row): bool => $row->database !== null && in_array($row->database, $scope, true),
        ));

        $summary = [];
        foreach ($scope as $schema) {
            $summary[$schema] = ['database' => $schema, 'connections' => 0, 'running' => 0];
        }
        foreach ($rows as $row) {
            ++$summary[$row->database]['connections'];
            if ($row->running) {
                ++$summary[$row->database]['running'];
            }
        }

        if ($activeOnly) {
            $rows = array_values(array_filter($rows, static fn (ProcessRow $row): bool => $row->running));
        }

        usort($rows, static fn (ProcessRow $a, ProcessRow $b): int => [$b->timeSeconds, $a->id] <=> [$a->timeSeconds, $b->id]);

        return [
            'summary_by_database' => array_values($summary),
            'processes' => array_map(static fn (ProcessRow $row): array => $row->toArray(), array_slice($rows, 0, $limit)),
            'truncated' => count($rows) > $limit,
        ];
    }
}
