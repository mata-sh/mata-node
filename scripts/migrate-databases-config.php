<?php

declare(strict_types=1);

use Mata\Config\DatabasesConfigParser;

require dirname(__DIR__) . '/vendor/autoload.php';

// Standalone: do not bootstrap the node or read config.env.
umask(0077);
set_error_handler(static function (): never {
    throw new RuntimeException('Filesystem or process operation failed');
});

/** @param list<string> $command */
function migrationCommand(array $command): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start metadata preservation tool');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Metadata preservation tool failed');
    }

    return $output;
}

/** @return array<string, int> */
function migrationStat(string $path): array
{
    clearstatcache(true, $path);
    $stat = lstat($path);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
        throw new RuntimeException('Source must be a regular file, not a symlink');
    }

    return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime']));
}

$temp = null;
$tempDir = null;
$stage = 'arguments';
try {
    $path = dirname(__DIR__) . '/config/databases.json';
    $backupDir = null;
    $engine = null;
    $write = false;
    $hasPath = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            fwrite(STDOUT, "Usage: php scripts/migrate-databases-config.php [PATH] --legacy-engine=mariadb|pgsql [--write --backup-dir=/private/directory]\nConverts the pre-0.3.0 format. Default: check only; PATH defaults to the node's config/databases.json.\n");
            exit(0);
        }
        if ($arg === '--write') {
            $write = true;
        } elseif (str_starts_with($arg, '--legacy-engine=')) {
            $engine = substr($arg, strlen('--legacy-engine='));
            if (!in_array($engine, ['mariadb', 'pgsql'], true)) {
                throw new RuntimeException('Invalid engine');
            }
        } elseif (str_starts_with($arg, '--backup-dir=')) {
            $backupDir = substr($arg, strlen('--backup-dir='));
        } elseif (!str_starts_with($arg, '-') && !$hasPath) {
            $path = $arg;
            $hasPath = true;
        } else {
            throw new RuntimeException('Invalid arguments');
        }
    }
    if ($write && ($backupDir === null || !str_starts_with($backupDir, '/'))) {
        throw new RuntimeException('Write requires an absolute backup directory');
    }

    $stage = 'source read (regular file required)';
    $before = migrationStat($path);
    $source = fopen($path, 'rb');
    if (!flock($source, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Source is locked');
    }
    $sourceStat = fstat($source);
    if ($sourceStat['ino'] !== $before['ino'] || $sourceStat['dev'] !== $before['dev']) {
        throw new RuntimeException('Source changed');
    }
    $original = stream_get_contents($source);

    $stage = 'configuration validation (expects the pre-0.3.0 format and --legacy-engine=mariadb|pgsql)';
    $object = json_decode($original, false, 512, JSON_THROW_ON_ERROR);
    if (!$object instanceof stdClass) {
        throw new RuntimeException('Expected object');
    }
    $config = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
    $parser = new DatabasesConfigParser();
    if ($config === [] || array_key_exists('connections', $config)) {
        $parser->parse($config);
        fwrite(STDOUT, "Already current or disabled; unchanged.\n");
        exit(0);
    }
    if ($engine === null) {
        throw new RuntimeException('Legacy engine required');
    }
    $connections = [];
    foreach ($object as $alias => $entry) {
        if (!$entry instanceof stdClass || (string) $alias === '') {
            throw new RuntimeException('Invalid legacy entry');
        }
        $values = (array) $entry;
        $keys = array_keys($values);
        sort($keys);
        if ($keys !== ['database', 'description', 'host', 'password', 'port', 'username'] || !is_string($values['database']) || $values['database'] === '') {
            throw new RuntimeException('Invalid legacy fields');
        }
        $connections[$alias] = [
            'engine' => $engine,
            'connection' => array_intersect_key($values, array_flip(['host', 'port', 'username', 'password'])),
            'databases' => [$values['database'] => $values['description']],
        ];
    }
    $converted = ['connections' => (object) $connections];
    $replacement = json_encode($converted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $parser->parse(json_decode($replacement, true, 512, JSON_THROW_ON_ERROR));
    if (!$write) {
        fwrite(STDOUT, "Valid; conversion needed. Check only, no files written.\n");
        exit(0);
    }

    $stage = 'metadata prerequisites (GNU cp required)';
    if (!str_contains(migrationCommand(['cp', '--version']), 'GNU coreutils')) {
        throw new RuntimeException('GNU cp required');
    }
    $stage = 'private backup directory (owned by this user, mode 0700 required)';
    if (!file_exists($backupDir) && !is_link($backupDir)) {
        mkdir($backupDir, 0700);
    }
    $directoryStat = lstat($backupDir);
    if (($directoryStat['mode'] & 0177777) !== 0040700 || $directoryStat['uid'] !== posix_geteuid()) {
        throw new RuntimeException('Unsafe backup directory');
    }

    $stage = 'replacement preparation';
    $tempDir = dirname($path) . '/.databases-migration-' . bin2hex(random_bytes(12));
    mkdir($tempDir, 0700);
    $temp = $tempDir . '/databases.json';
    $destination = fopen($temp, 'x+b');
    if (fwrite($destination, $replacement) !== strlen($replacement) || !fflush($destination) || !fsync($destination)) {
        throw new RuntimeException('Incomplete replacement');
    }
    fclose($destination);
    // GNU cp preserves POSIX ACLs with mode; explicit xattr makes failures fatal.
    migrationCommand(['cp', '--attributes-only', '--preserve=mode,ownership,timestamps,xattr', '--', $path, $temp]);
    touch($temp, $sourceStat['mtime'], $sourceStat['atime']);
    $prepared = migrationStat($temp);
    foreach (['uid', 'gid', 'mode'] as $key) {
        if ($prepared[$key] !== $before[$key]) {
            throw new RuntimeException('Metadata mismatch');
        }
    }

    $stage = 'private backup';
    $backup = $backupDir . '/databases-' . bin2hex(random_bytes(16)) . '.json';
    $handle = fopen($backup, 'x+b');
    // Also restrict the effective mask of any inherited ACL.
    chmod($backup, 0600);
    if (fwrite($handle, $original) !== strlen($original) || !fflush($handle) || !fsync($handle)) {
        throw new RuntimeException('Incomplete backup');
    }
    fclose($handle);

    $stage = 'source-change guard';
    if (migrationStat($path) !== $before || file_get_contents($path) !== $original || migrationStat($path) !== $before) {
        throw new RuntimeException('Source changed');
    }
    $stage = 'atomic replacement';
    rename($temp, $path);
    $temp = null;
    rmdir($tempDir);
    $tempDir = null;
    fwrite(STDOUT, "Migrated; original bytes saved in the private backup directory.\n");
} catch (Throwable) {
    // Parser errors can include operator-controlled keys. Never print exception details.
    fwrite(STDERR, "Migration failed during {$stage}; no credential details printed.\n");
    exit(1);
} finally {
    restore_error_handler();
    if ($temp !== null && is_file($temp)) {
        @unlink($temp);
    }
    if ($tempDir !== null && is_dir($tempDir)) {
        @rmdir($tempDir);
    }
}
