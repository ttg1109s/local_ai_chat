"""Verification domain for the Python tool worker."""
from .common import *
from .math_ops import safe_calculate

def verify_tool(args):
    kind = args.get("kind")
    if kind == "arithmetic":
        expected = safe_calculate(str(args.get("expression", "")))
        actual = args.get("actual")
        if isinstance(actual, bool) or not isinstance(actual, (int, float)) or not math.isfinite(float(actual)): raise ValueError("actual must be a finite number.")
        if not math.isfinite(float(expected)): raise ValueError("Independent calculation produced a non-finite result.")
        tolerance = float(args.get("tolerance", 1e-9))
        if not math.isfinite(tolerance) or tolerance < 0: raise ValueError("tolerance must be a finite non-negative number.")
        passed = math.isclose(float(expected), float(actual), rel_tol=tolerance, abs_tol=tolerance)
        return {"verified": passed, "independent_value": expected, "reported_value": actual,
                "absolute_error": abs(float(expected)-float(actual)), "method": "independent AST arithmetic recomputation"}
    if kind == "record_count":
        records = args.get("records")
        if not isinstance(records, list): raise ValueError("records must be an array.")
        raw_reported = args.get("reported_count", -1)
        if isinstance(raw_reported, bool) or not isinstance(raw_reported, int): raise ValueError("reported_count must be an integer.")
        reported = raw_reported
        return {"verified": reported == len(records), "independent_count": len(records), "reported_count": reported,
                "method": "independent record recount"}
    raise ValueError("Verification kind supports only arithmetic and record_count.")
