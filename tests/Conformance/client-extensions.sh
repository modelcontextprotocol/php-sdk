#!/usr/bin/env sh
#
# Runs the client against the conformance scenarios of the authorization extensions.
#
# `--suite all` leaves extension scenarios out, and `--spec-version` skips them with exit
# code 0, so they run one by one, without a version, and a skipped one fails the run.
#
#   CONFORMANCE="npx --yes @modelcontextprotocol/conformance@<version>" tests/Conformance/client-extensions.sh

set -eu

directory=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
conformance=${CONFORMANCE:?Set CONFORMANCE to the command that starts the conformance runner.}

for scenario in auth/client-credentials-basic auth/client-credentials-jwt auth/enterprise-managed-authorization; do
    echo "=== $scenario"

    if ! output=$(cd "$directory" && $conformance client --command "php $directory/client.php" --scenario "$scenario" 2>&1); then
        echo "$output"
        exit 1
    fi

    echo "$output"

    case $output in
        *SKIPPED*)
            echo "The scenario $scenario was skipped instead of run." >&2
            exit 1
            ;;
    esac
done
