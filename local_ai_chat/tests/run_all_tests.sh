#!/usr/bin/env sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
sh "$ROOT/tests/run_agent_suite.sh"
if command -v python3 >/dev/null 2>&1; then
  for test_file in "$ROOT"/tests/*_test.py; do
    python3 "$test_file"
  done
  echo "All Python tests passed."
else
  echo "SKIP: python3 unavailable; Python tests not run."
fi
