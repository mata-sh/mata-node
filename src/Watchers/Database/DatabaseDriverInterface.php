<?php

declare(strict_types=1);

namespace Mata\Watchers\Database;

use Mata\Config\Dto\DatabaseConfig;
use Mata\Core\DatabaseInterface;
use PDOException;
use UnexpectedValueException;

/**
 * Engine-specific part of database monitoring: builds the DSN and turns query rows
 * into engine-neutral values. Filtering, grouping and response shape are shared.
 */
interface DatabaseDriverInterface
{
    public function engine(): string;

    public function dsn(DatabaseConfig $config): string;

    /**
     * @throws PDOException             If a query fails
     * @throws UnexpectedValueException If a value the engine must provide is missing or not numeric
     */
    public function overview(DatabaseInterface $db): OverviewStatus;

    /**
     * Sessions other than the monitoring query itself.
     *
     * @return list<ProcessRow>
     *
     * @throws PDOException If the query fails
     */
    public function processes(DatabaseInterface $db): array;

    /**
     * @throws PDOException If the query fails
     */
    public function visibility(DatabaseInterface $db): Visibility;
}
