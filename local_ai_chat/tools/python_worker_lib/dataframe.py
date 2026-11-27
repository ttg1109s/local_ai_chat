"""Dataframe domain for the Python tool worker."""
from .common import *

def dataframe_tool(args):
    """Bounded DataFrame operations. pandas is optional; JSON records are the interchange format."""
    import pandas as pd
    rows = args.get("data")
    if isinstance(rows, str):
        rows = json.loads(rows)
    if not isinstance(rows, list) or len(rows) > 100000:
        raise ValueError("data must be an array of records with at most 100,000 rows.")
    df = pd.DataFrame(rows)
    operation = str(args.get("operation", "profile"))
    if operation == "profile":
        return {"rows": int(len(df)), "columns": list(map(str, df.columns)),
                "dtypes": {str(k): str(v) for k, v in df.dtypes.items()},
                "null_counts": {str(k): int(v) for k, v in df.isna().sum().items()},
                "describe": json.loads(df.describe(include="all").to_json(date_format="iso"))}
    if operation == "filter":
        col = args.get("column")
        if col not in df.columns: raise ValueError("Filter column not found.")
        op, val = args.get("operator", "eq"), args.get("value")
        funcs = {"eq": lambda s: s == val, "ne": lambda s: s != val, "gt": lambda s: s > val,
                 "gte": lambda s: s >= val, "lt": lambda s: s < val, "lte": lambda s: s <= val,
                 "contains": lambda s: s.astype(str).str.contains(str(val), regex=False, na=False)}
        if op not in funcs: raise ValueError("Filter operator is not supported.")
        out = df.loc[funcs[op](df[col])].head(10000)
        return {"rows": int(len(out)), "records": json.loads(out.to_json(orient="records", date_format="iso"))}
    if operation == "groupby":
        col, agg = args.get("column"), args.get("aggregation", "count")
        if col not in df.columns: raise ValueError("Group-by column not found.")
        value_col = args.get("value_column")
        if agg not in ("count", "sum", "mean", "min", "max"): raise ValueError("Invalid aggregation operation.")
        grouped = df.groupby(col, dropna=False)
        result = grouped.size() if agg == "count" else getattr(grouped[value_col], agg)()
        return {"groups": json.loads(result.reset_index(name="value").to_json(orient="records"))}
    raise ValueError("DataFrame operations support only profile, filter, and groupby.")
