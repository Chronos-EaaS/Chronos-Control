#!/bin/sh
set -eu
if [ "${CHRONOS_TEST_STACK:-}" != "1" ]; then
    echo 'This image runs only as the isolated Chronos test stack.' >&2
    exit 1
fi
php /opt/chronos/tests/fixtures/seed.php
exec apache2-foreground
