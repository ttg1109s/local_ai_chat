"""Random number generation domain for the Python tool worker."""
from .common import *

def generate_number_tool(args):
    distribution = args.get("distribution")
    if distribution not in ("binary", "integer", "uniform", "normal"):
        raise ValueError("distribution must be binary, integer, uniform, or normal.")

    shape = args.get("shape", "vector")
    if shape not in ("vector", "matrix"):
        raise ValueError("shape must be vector or matrix.")

    number_type = args.get("number_type")
    if number_type is None:
        number_type = "integer" if distribution in ("binary", "integer") else "float"
    if number_type not in ("integer", "float"):
        raise ValueError("number_type must be integer or float.")
    if distribution == "binary" and number_type != "integer":
        raise ValueError("binary distribution only supports integer output.")

    raw_series_count = args.get("series_count", 1)
    if isinstance(raw_series_count, bool) or not isinstance(raw_series_count, int):
        raise ValueError("series_count must be an integer.")
    series_count = raw_series_count
    if not 1 <= series_count <= 20:
        raise ValueError("series_count must be between 1 and 20.")

    if shape == "vector":
        raw_count = args.get("count", 10)
        if isinstance(raw_count, bool) or not isinstance(raw_count, int):
            raise ValueError("count must be an integer.")
        count = raw_count
        if not 1 <= count <= 10000:
            raise ValueError("count must be between 1 and 10,000.")
        rows = columns = None
        total_items = count
    else:
        raw_rows = args.get("rows")
        raw_columns = args.get("columns")
        if isinstance(raw_rows, bool) or not isinstance(raw_rows, int) or isinstance(raw_columns, bool) or not isinstance(raw_columns, int):
            raise ValueError("rows and columns must be integers for matrix shape.")
        rows, columns = raw_rows, raw_columns
        if not 1 <= rows <= 10000 or not 1 <= columns <= 10000:
            raise ValueError("rows and columns must each be between 1 and 10,000.")
        total_items = rows * columns
        if total_items > 10000:
            raise ValueError("matrix may contain at most 10,000 values.")
        count = total_items

    low = float(args.get("min", 0))
    high = float(args.get("max", 100))
    if not math.isfinite(low) or not math.isfinite(high):
        raise ValueError("min and max must be finite numbers.")
    if distribution in ("integer", "uniform") and low >= high:
        raise ValueError("min must be less than max.")
    if distribution == "uniform" and not math.isfinite(high - low):
        raise ValueError("Uniform range is too large to represent safely.")
    if distribution == "integer" and math.ceil(low) > math.floor(high):
        raise ValueError("min and max must include at least one integer value.")
    if number_type == "integer" and distribution == "uniform" and math.ceil(low) > math.floor(high):
        raise ValueError("Integer output requires at least one integer within min and max.")

    mean = float(args.get("mean", 0))
    std = float(args.get("std", 1))
    if distribution == "normal" and (not math.isfinite(mean) or not math.isfinite(std) or std <= 0):
        raise ValueError("mean must be finite and std must be a finite number greater than 0.")
    probability = float(args.get("probability", 0.5))
    if distribution == "binary" and (not math.isfinite(probability) or not 0 <= probability <= 1):
        raise ValueError("probability must be finite and between 0 and 1.")

    base_seed = args.get("seed")
    if base_seed is not None and (isinstance(base_seed, bool) or not isinstance(base_seed, int)):
        raise ValueError("seed must be an integer or null.")

    def sample_one(rng):
        if distribution == "binary":
            return 1 if rng.random() < probability else 0
        if distribution == "integer":
            value = rng.randint(math.ceil(low), math.floor(high))
        elif distribution == "uniform":
            value = rng.uniform(low, high)
            if number_type == "integer":
                value = round(value)
        else:
            value = rng.gauss(mean, std)
            if number_type == "integer":
                value = round(value)
        if isinstance(value, float) and not math.isfinite(value):
            raise ValueError("Generation produced a non-finite value; reduce the range or parameters.")
        return int(value) if number_type == "integer" else float(value)

    datasets = []
    for index in range(series_count):
        seed = None if base_seed is None else int(base_seed) + index
        rng = random.Random(seed)
        flat_values = [sample_one(rng) for _ in range(total_items)]
        values = flat_values if shape == "vector" else [
            flat_values[row * columns:(row + 1) * columns] for row in range(rows)
        ]
        datasets.append({"name": f"Series {index + 1}", "values": values, "seed": seed})

    result = {
        "distribution": distribution,
        "shape": shape,
        "number_type": number_type,
        "count": count if shape == "vector" else None,
        "rows": rows,
        "columns": columns,
        "series_count": series_count,
        "seed": base_seed,
        "datasets": datasets,
    }
    if series_count == 1:
        result["values"] = datasets[0]["values"]
    return result
