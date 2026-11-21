#!/bin/sh
set -eu

if [ "$#" -ne 1 ]; then
    echo "Usage: build.sh NEW_OUTPUT_DIRECTORY" >&2
    exit 2
fi
script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
output=$1
mkdir -- "$output"
"${CC:-cc}" -std=c99 -O2 -Wall -Wextra -Werror "$script_dir/marker.c" -o "$output/marker"
printf '%s\n' 'synthetic-workload-v1' > "$output/workload.txt"
"$script_dir/../artifacts.php" generate "$output" sut=marker workload=workload.txt > "$output/manifest.json"
