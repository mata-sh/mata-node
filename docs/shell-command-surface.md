# Shell command surface

Policy: keep shell commands for performant OS/log work, but build command strings only in `src/Services/Command/ShellCommands.php`.

| Builder | Command shape | Inputs | Risk | Decision |
|---|---|---|---|---|
| `ShellCommands::tail()` | `tail -n N -- FILE` | file path, line count | path/arg injection | Keep shell. Centralized escaping and line clamp. |
| `ShellCommands::lineCount()` | `wc -l < FILE` | file path | path injection | Keep shell for large logs. Centralized escaping. |
| `ShellCommands::composerAudit()` | `composer audit ... --working-dir=DIR` | optional composer bin path, working dir | config path injection | Keep shell. Bin/path escaped; default command is literal `composer`. |
| `ShellCommands::processCount()` | `pgrep -c[x] PATTERN 2>/dev/null` | process regex | regex/shell injection | Keep shell. Pattern character allowlist plus escaping. |
| `ShellCommands::memoryTotalKb()` | `grep MemTotal /proc/meminfo | awk '{print $2}'` | none | low | Keep shell for now. No external input. |
| `ShellCommands::memoryAvailableKb()` | `grep MemAvailable /proc/meminfo | awk '{print $2}'` | none | low | Keep shell for now. No external input. |
| `ShellCommands::cpuUsagePercent()` | `top -bn1 | grep 'Cpu(s)' | awk '{print 100 - $8}'` | none | low | Keep shell. OS primitive. |
| `ShellCommands::totalProcessCount()` | `ps -e --no-headers | wc -l` | none | low | Keep shell. OS primitive. |
| `ShellCommands::phpProcesses()` | `ps -eo ... | grep "[p]hp"` | none | low | Keep shell. OS primitive. |
| `ShellCommands::fileCount()` | `du -a -- PATH | wc -l` | root path | path injection | Keep shell for speed. Path escaped. |
| `ShellCommands::diskFree()` | `df -k -- PATH 2>/dev/null` | path | path injection | Fallback only. Path escaped. |
| `ShellCommands::packageCountCommands()` | `dpkg-query`, `rpm`, `pacman` counts | none | low | Keep shell. OS/package-manager primitives. |

Rules:

- Watchers and log parsers must call builders, not concatenate shell strings.
- Log analysis (`/apps` counts and fatal cursor) does not shell out at all: parsers read the file
  once as a bounded-memory PHP stream. Format detection reads the first lines the same way. The
  former `ShellCommands::grepCount()` and `ShellCommands::head()` builders were removed with their
  last callers.
- Shell paths go through `ShellInput::path()` and `escapeshellarg()` in the builder.
- Counts go through `ShellInput::positiveInt()`.
- Process patterns go through `ShellInput::safeProcessPattern()`.
- Direct `shell_exec`, `proc_open`, `escapeshellarg`, and inline command strings outside `src/Services/Command` are blocked by `ShellCommandSurfaceTest`.
- `trash/` was deleted as dead unsafe code/reference material.
