#!/usr/bin/env sh
#
# Brings the end-to-end environment up, runs the client against it, and tears it down.
#
#   tests/E2E/run.sh              full run
#   E2E_KEEP=1 tests/E2E/run.sh   leave the containers up afterwards, to poke at them
#
# Ports can be moved with E2E_KEYCLOAK_PORT and E2E_MCP_PORT if something already
# holds the defaults.

set -eu

directory=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
compose="docker compose -f $directory/docker-compose.yml"

keycloak_port=${E2E_KEYCLOAK_PORT:-8181}
mcp_port=${E2E_MCP_PORT:-8001}

if [ ! -d "$directory/../../vendor" ]; then
    echo "Install the dependencies first: composer install" >&2
    exit 1
fi

cleanup() {
    if [ "${E2E_KEEP:-0}" = "1" ]; then
        echo "Leaving the environment up. Stop it with: $compose down -v"
        return
    fi

    $compose down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

mkdir -p "$directory/sessions"
chmod 0777 "$directory/sessions"

echo "Starting Keycloak and the MCP server..."
E2E_KEYCLOAK_PORT="$keycloak_port" E2E_MCP_PORT="$mcp_port" $compose up -d --wait

# --wait covers Keycloak's health check; nginx and php-fpm have none, so the MCP
# endpoint is polled until it answers with the 401 that means it is serving.
echo "Waiting for the MCP endpoint..."
attempt=0
until [ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "http://localhost:$mcp_port/mcp" || true)" = "401" ]; do
    attempt=$((attempt + 1))
    if [ "$attempt" -gt 60 ]; then
        echo "The MCP server never came up." >&2
        $compose logs
        exit 1
    fi
    sleep 1
done

echo "Running the client..."
MCP_E2E_ENDPOINT="http://localhost:$mcp_port/mcp" \
MCP_E2E_CREDENTIALS="$directory/sessions/credentials.json" \
    php "$directory/client.php"
