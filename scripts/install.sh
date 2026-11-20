#!/bin/sh
# MATA Node installer: curl -fsSL https://mata.sh/install-node.sh | sh
#
# Clones a release into ./mata-node, writes the dashboard credentials and runs the unattended
# setup. Native by default, so the node reads the host directly; you then point a web server at
# public/. --docker runs it in a container, which sees only what you mount into it.
# Never runs sudo. Safe to run again.
#
# Everything runs from main() on the last line, so a download cut short does nothing.
set -eu

# The release workflow writes the tag here; empty means the newest release.
MATA_VERSION_DEFAULT=
REPO_URL=${MATA_NODE_REPO_URL:-https://github.com/mata-sh/mata-node.git}
DOCS_URL=https://docs.mata.sh

usage() {
    cat <<'EOF'
Usage: curl -fsSL https://mata.sh/install-node.sh | sh -s -- [options]

  --native          Native install, the default (PHP 8.3 and Composer; the web server is yours)
  --docker          Docker install (sees the host only through mounts you add)
  --dir DIR         Checkout directory (default: ./mata-node)
  --version TAG     Release to install (default: the newest)
  --server-id NAME  This node's name on the dashboard (DASHBOARD_SERVER_ID)
  --dry-run         Print the commands instead of running them
  -h, --help        Show this help

Run `server add` on the dashboard first: it prints DASHBOARD_SERVER_ID and DASHBOARD_API_KEY.
The key is not an option, so it stays out of shell history and the process list: the installer
asks for it on the terminal, or reads MATA_NODE_API_KEY.

Environment: MATA_NODE_REPO_URL, MATA_NODE_DIR, MATA_NODE_VERSION, MATA_NODE_SERVER_ID,
MATA_NODE_API_KEY.
EOF
}

say() { printf '%s\n' "$*"; }
fail() {
    printf 'Error: %s\n' "$1" >&2
    shift
    for line in "$@"; do printf '%s\n' "$line" >&2; done
    exit 1
}

# Children get /dev/null as stdin: under curl | sh, stdin is the rest of this script.
run() {
    say "+ $*"
    [ "$dry_run" = yes ] || "$@" </dev/null
}

parse_args() {
    mode=native
    dir=${MATA_NODE_DIR:-mata-node}
    version=${MATA_NODE_VERSION:-$MATA_VERSION_DEFAULT}
    server_id=${MATA_NODE_SERVER_ID:-}
    api_key=${MATA_NODE_API_KEY:-}
    dry_run=no
    while [ $# -gt 0 ]; do
        case $1 in
            --native) mode=native ;;
            --docker) mode=docker ;;
            --dir) [ $# -ge 2 ] || fail "--dir needs a value"; dir=$2; shift ;;
            --version) [ $# -ge 2 ] || fail "--version needs a value"; version=$2; shift ;;
            --server-id) [ $# -ge 2 ] || fail "--server-id needs a value"; server_id=$2; shift ;;
            --api-key) fail "--api-key would leave the key in shell history; set MATA_NODE_API_KEY or enter it when asked" ;;
            --dry-run) dry_run=yes ;;
            -h|--help) usage; exit 0 ;;
            *) usage >&2; fail "unknown option: $1" ;;
        esac
        shift
    done
}

check_tools() {
    tools="git make"
    [ "$mode" = docker ] || tools="$tools php composer"
    missing=
    for tool in $tools; do
        command -v "$tool" >/dev/null 2>&1 || missing="$missing $tool"
    done
    [ -z "$missing" ] || fail "missing:$missing. Install them with your package manager, then run this again."
}

check_docker() {
    native="  or install natively, the default: curl -fsSL https://mata.sh/install-node.sh | sh"
    if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
        fail "Docker with the Compose plugin is not installed. Either" \
            "  install it (https://docs.docker.com/engine/install/), then run this again," \
            "$native"
    fi
    if ! docker_error=$(docker info 2>&1 >/dev/null); then
        case $docker_error in
            *"permission denied"*)
                fail "$(id -un) may not use Docker. Either" \
                    "  add the account to the docker group (root-equivalent), log in again, and rerun:" \
                    "     sudo usermod -aG docker $(id -un)" \
                    "  or run this as root," \
                    "$native" ;;
            *) fail "the Docker daemon is not running. Start it (e.g. sudo systemctl start docker), then run this again." ;;
        esac
    fi
}

# Prints a value from the checkout's config.env; empty when it or the file is missing.
env_value() {
    sed -n "s/^$1=//p" "$dir/config/config.env" 2>/dev/null | tail -n1 | tr -d '"[:space:]'
}

# Under curl | sh, stdin is this script; the terminal is /dev/tty, if there is one.
has_tty() {
    [ -t 2 ] && { true </dev/tty; } 2>/dev/null
}

# Asks only on a fresh install: a rerun keeps the configured key unless one is passed.
ask_credentials() {
    [ "$dry_run" = no ] && [ -z "$(env_value DASHBOARD_API_KEY)" ] && has_tty || return 0
    if [ -z "$server_id" ]; then
        printf "This node's name on the dashboard, DASHBOARD_SERVER_ID (Enter to skip): " >/dev/tty
        read -r server_id </dev/tty || server_id=
    fi
    if [ -z "$api_key" ]; then
        printf 'DASHBOARD_API_KEY from "server add" (hidden; Enter to skip): ' >/dev/tty
        trap 'stty echo </dev/tty; exit 130' INT TERM
        stty -echo </dev/tty
        read -r api_key </dev/tty || api_key=
        stty echo </dev/tty
        trap - INT TERM
        printf '\n' >/dev/tty
    fi
}

# The dashboard's server name rule; the key must not need quoting in config.env.
check_credentials() {
    if [ -n "$server_id" ]; then
        case $server_id in
            [!A-Za-z0-9]*|*[!A-Za-z0-9._-]*) fail "invalid server id '$server_id': use letters, digits, '.', '_' or '-', starting with a letter or digit" ;;
        esac
        [ ${#server_id} -le 64 ] || fail "invalid server id: at most 64 characters"
    fi
    case $api_key in
        *[!A-Za-z0-9._~+/=-]*) fail "the API key has characters config.env would need quoted; set it there by hand instead" ;;
    esac
}

resolve_version() {
    [ -z "$version" ] || return 0
    version=$(git ls-remote --tags --refs --sort=-v:refname "$REPO_URL" '[0-9]*' | head -n1 | sed 's|.*refs/tags/||')
    [ -n "$version" ] || fail "could not find a release at $REPO_URL"
}

checkout() {
    if [ -e "$dir" ]; then
        [ -f "$dir/bin/mata-node" ] && [ -d "$dir/.git" ] \
            || fail "$dir exists and is not a MATA Node checkout. Pass --dir to install elsewhere."
        current=$(git -C "$dir" describe --tags --exact-match 2>/dev/null || git -C "$dir" rev-parse --short HEAD)
        say "Using the existing checkout in $dir ($current); this installer does not upgrade it."
        return 0
    fi
    resolve_version
    say "Installing MATA Node $version into $dir"
    run git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$version" "$REPO_URL" "$dir"
}

# Rewrites config.env in place, keeping its mode and group (0600, or 0640 www-data in Docker).
# The value goes to awk through the environment, not its arguments.
set_env() {
    file=$dir/config/config.env
    tmp=$(mktemp "$dir/config/.config.env.XXXXXX")
    ENV_KEY=$1 ENV_VALUE=$2 awk '
        BEGIN { key = ENVIRON["ENV_KEY"]; value = ENVIRON["ENV_VALUE"] }
        index($0, key "=") == 1 { if (!done) print key "=" value; done = 1; next }
        { print }
        END { if (!done) print key "=" value }' "$file" >"$tmp"
    cat "$tmp" >"$file"
    rm -f "$tmp"
}

write_credentials() {
    [ -n "$server_id$api_key" ] || return 0
    names="${server_id:+DASHBOARD_SERVER_ID }${api_key:+DASHBOARD_API_KEY }"
    say "+ set ${names}in $dir/config/config.env"
    [ "$dry_run" = no ] || return 0
    [ -z "$server_id" ] || set_env DASHBOARD_SERVER_ID "$server_id"
    [ -z "$api_key" ] || set_env DASHBOARD_API_KEY "$api_key"
}

install_native() {
    run make -C "$dir" setup NONINTERACTIVE=1 ENV=prod
    write_credentials
}

# Credentials go in before the container starts: Compose reads config.env when it creates it.
install_docker() {
    run make -C "$dir" docker-setup NONINTERACTIVE=1
    write_credentials
    run make -C "$dir" prod-fix-perms
    run make -C "$dir" prod-start
}

# Sets port, url and health for summary_docker; health is empty when curl is missing.
verify_docker() {
    port=$(sed -n 's/^WEB_PORT=//p' "$dir/.env" 2>/dev/null | tr -d '"[:space:]')
    port=${port:-3310}
    url=http://127.0.0.1:$port
    health=
    command -v curl >/dev/null 2>&1 || return 0
    tries=0
    until health=$(curl -s -o /dev/null -w '%{http_code}' "$url/health"); do
        tries=$((tries + 1))
        [ "$tries" -lt 30 ] || fail "the node does not answer at $url/health. See: make -C $dir logs"
        sleep 2
    done
}

# The steps both summaries share; $1 is how to run bin/mata-node there.
credential_steps() {
    # Without a key, config.env still holds the example name.
    id=$server_id
    if [ -n "$(env_value DASHBOARD_API_KEY)" ]; then
        id=${id:-$(env_value DASHBOARD_SERVER_ID)}
    else
        cat <<EOF
  - Set DASHBOARD_SERVER_ID and DASHBOARD_API_KEY in $dir/config/config.env. On the dashboard,
       make mata ARGS="server add <name> --api-url https://node.example.com/"
    prints both.
EOF
    fi
    cat <<EOF
  - Check the configuration:
       $1
  - Then, on the dashboard:
       make mata ARGS="server test ${id:-<name>}"
EOF
}

summary_docker() {
    case $health in
        200) state="It is running at $url (this host only)." ;;
        '') state="It should be running at $url (this host only)." ;;
        *) state="It answers at $url, but /health returned $health: see the check below." ;;
    esac
    cat <<EOF

MATA Node is installed in $dir. $state

Next steps:
  - In its container, the node sees only its own files and processes. Mount the apps and logs
    to watch in $dir/docker-compose.prod.yml and describe the apps in config/apps.json:
       $DOCS_URL/node/docker/#monitoring-the-host-from-a-container
    Apply changes to the mounts or config.env with: make -C $dir prod-start
  - Put an HTTPS reverse proxy in front of port $port.
EOF
    credential_steps "make -C $dir mata ARGS=\"config validate\""
    cat <<EOF

Docs:      $DOCS_URL/guides/first-server/
Uninstall: make -C $dir prod-stop, then delete $dir as root (the container's files in logs/
           and temp/ belong to its www-data)
EOF
}

summary_native() {
    parent=$(CDPATH='' cd -- "$dir/.." && pwd)
    cat <<EOF

MATA Node is prepared in $dir. Finish the install yourself:

  - Serve $dir/public over HTTPS with your web server
    (examples: $dir/docs/examples/webserver-configs/).
    The node reads config/ and writes logs/ and temp/; config/ is readable by $(id -un) only.
    If PHP runs as another user, give that user those rights.
  - Describe the apps to watch in $dir/config/apps.json. Relative paths resolve against
    $parent.
EOF
    credential_steps "php $dir/bin/mata-node config validate   (as the PHP user)"
    cat <<EOF

Docs:      $DOCS_URL/guides/first-server/
Uninstall: remove the web server site, then delete $dir
EOF
}

main() {
    parse_args "$@"
    check_tools
    if [ "$mode" = docker ]; then check_docker; fi
    ask_credentials
    check_credentials
    checkout
    if [ "$mode" = docker ]; then install_docker; else install_native; fi
    if [ "$dry_run" = yes ]; then exit 0; fi
    if [ "$mode" = docker ]; then
        verify_docker
        summary_docker
    else
        summary_native
    fi
}

main "$@"
