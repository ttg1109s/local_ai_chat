#!/usr/bin/env sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
for test_file in "$ROOT"/tests/*_test.php; do
  php "$test_file"
done
php "$ROOT/tests/agent/protocol_schema_test.php"
echo "All PHP tests passed."
