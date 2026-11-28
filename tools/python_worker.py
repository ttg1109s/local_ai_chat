#!/usr/bin/env python3
"""Stable CLI entry point; business logic lives in python_worker_lib modules.

This file intentionally preserves the legacy path used by PHP and test harnesses.
"""
import sys
from pathlib import Path

# Tests and embedding callers may load this file by absolute path rather than
# execute it as a script; make the adjacent domain package importable in both cases.
_TOOL_DIR = str(Path(__file__).resolve().parent)
if _TOOL_DIR not in sys.path:
    sys.path.insert(0, _TOOL_DIR)

for _stream in (sys.stdin, sys.stdout, sys.stderr):
    try:
        _stream.reconfigure(encoding="utf-8", errors="strict")
    except (AttributeError, OSError, ValueError):
        pass

# Re-export the historical function surface for existing tests/integrations.
from python_worker_lib.math_ops import *  # noqa: F401,F403
from python_worker_lib.data_generation import *  # noqa: F401,F403
from python_worker_lib.analytics import *  # noqa: F401,F403
from python_worker_lib.visualization import *  # noqa: F401,F403
from python_worker_lib.dataframe import *  # noqa: F401,F403
from python_worker_lib.verification import *  # noqa: F401,F403
from python_worker_lib.protocol import read_input, _json_safe
from python_worker_lib.entrypoint import main

if __name__ == "__main__":
    main()
