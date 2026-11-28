import json
import subprocess
import sys
from pathlib import Path

WORKER = Path(__file__).resolve().parents[1] / "tools" / "python_worker.py"

def run(payload):
    proc = subprocess.run(
        [sys.executable, str(WORKER)],
        input=json.dumps(payload),
        text=True,
        capture_output=True,
        timeout=20,
    )
    assert proc.returncode == 0, proc.stderr
    return json.loads(proc.stdout)

same = run({"action": "text_diff", "arguments": {"old_text": "a\nb\n", "new_text": "a\nb\n"}})
assert same["success"] and same["result"]["identical"]
assert same["result"]["summary"]["changes"] == 0
assert same["result"]["summary"]["equal_units"] == 2

changed = run({"action": "text_diff", "arguments": {"old_text": "alpha\nbeta\n", "new_text": "alpha\ngamma\n"}})
assert changed["success"] and len(changed["result"]["changes"]) == 1
assert changed["result"]["changes"][0]["operation"] == "replace"
assert changed["result"]["changes"][0]["old_range"] == {"start": 2, "end": 2}
assert changed["result"]["changes"][0]["new_range"] == {"start": 2, "end": 2}

added = run({"action": "text_diff", "arguments": {"old_text": "one\n", "new_text": "one\ntwo\n"}})
assert added["success"] and added["result"]["changes"][0]["operation"] == "insert"
assert added["result"]["changes"][0]["old_range"]["start"] is None

removed = run({"action": "text_diff", "arguments": {"old_text": "one\ntwo\n", "new_text": "one\n"}})
assert removed["success"] and removed["result"]["changes"][0]["operation"] == "delete"

char = run({"action": "text_diff", "arguments": {"old_text": "colour", "new_text": "color", "mode": "character", "include_html": True}})
assert char["success"] and char["result"]["mode"] == "character"
assert "<table" in char["result"]["html_diff"]

invalid = run({"action": "text_diff", "arguments": {"old_text": "a", "new_text": "b", "context_lines": 11}})
assert not invalid["success"]
print("Python text diff tests passed.")
