<?php

declare(strict_types=1);

namespace Mata\Watchers\Database;

/**
 * Whether the monitoring account can see every session on the server.
 */
enum Visibility: string
{
    case AllSessions = 'all_sessions';
    case OwnSessions = 'own_sessions';
}
