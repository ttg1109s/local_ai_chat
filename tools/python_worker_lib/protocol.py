"""Protocol domain for the Python tool worker."""
from .common import *

def read_input():
    raw = sys.stdin.read(MAX_INPUT + 1)
    if len(raw) > MAX_INPUT:
        raise ValueError("Input exceeds the 2 MB limit.")
    data = json.loads(raw or "{}")
    if not isinstance(data, dict):
        raise ValueError("Input must be a JSON object.")
    return data

def _json_safe(value):
    """Convert NumPy scalars and non-finite floats into strict JSON-compatible values."""
    if hasattr(value, "item"):
        try:
            value = value.item()
        except Exception:
            pass
    if isinstance(value, dict):
        return {str(k): _json_safe(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_json_safe(v) for v in value]
    if isinstance(value, float):
        if math.isnan(value):
            return None
        if math.isinf(value):
            return "Infinity" if value > 0 else "-Infinity"
    return value
