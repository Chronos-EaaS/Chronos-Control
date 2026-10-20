#!/bin/sh
set -eu
if [ "${CHRONOS_TEST_STACK:-}" != "1" ]; then
    echo 'This image runs only as the isolated Chronos test stack.' >&2
    exit 1
fi
php /opt/chronos/tests/fixtures/seed.php
if [ "${CHRONOS_TEST_V3:-}" = "1" ]; then
    # The fixed fixture runs the same foreground PHP daemon as production.
    # Both processes share only this disposable container's runtime filesystem.
    apache2-foreground &
    chronos_apache_pid=$!
    chronos_live_pid=
    chronos_stop() {
        trap - EXIT HUP INT TERM
        if [ -n "$chronos_live_pid" ]; then kill "$chronos_live_pid" 2>/dev/null || true; fi
        kill "$chronos_apache_pid" 2>/dev/null || true
        wait || true
    }
    trap chronos_stop EXIT HUP INT TERM
    while kill -0 "$chronos_apache_pid" 2>/dev/null; do
        if [ -z "$chronos_live_pid" ] || ! kill -0 "$chronos_live_pid" 2>/dev/null; then
            if [ -n "$chronos_live_pid" ]; then wait "$chronos_live_pid" || true; fi
            php /opt/chronos/api/live.php &
            chronos_live_pid=$!
        fi
        sleep 1
    done
    wait "$chronos_apache_pid"
    exit $?
fi
exec apache2-foreground
